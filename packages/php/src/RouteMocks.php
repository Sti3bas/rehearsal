<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Utils;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class RouteMocks
{
    public static function register(Attempt $attempt, array $input, bool $fake): void
    {
        abort_if(! $fake && config('rehearsal.route_mocks') !== true, 404);
        abort_if(strlen(json_encode($input, JSON_THROW_ON_ERROR)) > 128_000, 422);
        $key = $fake ? 'responses.*' : 'response';
        $fields = 'status,jsonBody,body,headers,delay,once,times';
        $data = Validator::validate($input, [
            'path' => ['required', 'string', 'max:1024', 'starts_with:/', 'not_regex:/^\/\//'],
            'method' => [$fake ? 'required' : 'nullable', 'string', Rule::in(FakeRoutes::METHODS)],
            ...($fake ? [
                'responses' => 'required_without:callback|prohibits:callback|array|list|min:1|max:20',
                'callback' => 'required_without:responses|array:port,token,id',
                'callback.port' => 'required_with:callback|integer|between:1,65535',
                'callback.token' => 'required_with:callback|string|regex:/\A[a-f0-9]{64}\z/',
                'callback.id' => 'required_with:callback|string|regex:/\A[a-f0-9]{32}\z/',
            ] : []),
            $key => 'present|array:'.$fields.($fake ? '' : ',abort,networkError,gate,passthrough'),
            "$key.status" => 'sometimes|integer|min:200|max:599',
            "$key.jsonBody" => 'sometimes|string|json|max:100000',
            "$key.body" => 'sometimes|string|max:100000',
            "$key.headers" => 'sometimes|array|max:20',
            "$key.headers.*" => 'required|string|max:1024',
            "$key.delay" => 'sometimes|integer|min:0|max:10000',
            "$key.once" => 'sometimes|boolean',
            "$key.times" => 'sometimes|integer|min:1',
            ...($fake ? [] : [
                'response.abort' => 'sometimes|boolean',
                'response.networkError' => 'sometimes|boolean',
                'response.gate' => 'sometimes|string|regex:/\A[a-f0-9]{32}\z/',
                'response.passthrough' => 'sometimes|boolean',
            ]),
        ]);
        if ($fake) {
            abort_unless(FakeRoutes::allows($data['method'], $data['path']), 422);
        } else {
            $data['responses'] = [$data['response']];
            unset($data['response']);
        }
        foreach ($data['responses'] ?? [] as $response) {
            abort_if(isset($response['body'], $response['jsonBody'])
                || (! empty($response['once']) && isset($response['times']))
                || (! empty($response['passthrough']) && array_intersect_key($response, array_flip(['status', 'body', 'jsonBody', 'headers', 'abort', 'networkError']))), 422);
            foreach ($response['headers'] ?? [] as $name => $value) {
                abort_unless(preg_match('/\A[a-zA-Z0-9!#$%&\'*+.^_`|~-]+\z/', $name)
                    && ! preg_match('/[\r\n]/', $value), 422);
            }
        }

        AttemptData::update($attempt, $fake ? 'route-fakes' : 'route-mocks', function (array &$rules) use ($attempt, $data): void {
            abort_if(count($rules) >= 64, 422);
            if (isset($data['responses'][0]['gate'])) {
                $gate = $data['responses'][0]['gate'];
                AttemptData::update($attempt, 'route-gates', function (array &$gates) use ($gate): void {
                    abort_if(count($gates) >= 64 || isset($gates[$gate]), 422);
                    $gates[$gate] = ['claimed' => false, 'released' => false];
                });
            }
            $rules[] = $data;
        });
    }

    public static function clear(Attempt $attempt, bool $fake): void
    {
        abort_if(! $fake && config('rehearsal.route_mocks') !== true, 404);
        AttemptData::update($attempt, $fake ? 'route-fakes' : 'route-mocks', static function (array &$rules): void {
            $rules = [];
        });
    }

    public static function release(Attempt $attempt, array $input): void
    {
        abort_unless(config('rehearsal.route_mocks') === true, 404);
        $data = Validator::validate($input, ['gate' => ['required', 'string', 'regex:/\A[a-f0-9]{32}\z/']]);
        AttemptData::update($attempt, 'route-gates', static function (array &$gates) use ($data): void {
            abort_unless(isset($gates[$data['gate']]), 404);
            $gates[$data['gate']]['released'] = true;
        });
    }

    public function gate(Request $request, Attempt $attempt, string $gate): JsonResponse
    {
        abort_unless($request->hasHeader(Protocol::MARKER), 403);
        $state = AttemptData::update($attempt, 'route-gates', static fn (array $gates) => $gates[$gate] ?? null);
        abort_if($state === null, 404);

        return response()->json(['data' => $state])->header('Cache-Control', 'no-store');
    }

    public function resolve(Request $request, Attempt $attempt): JsonResponse
    {
        abort_unless($request->hasHeader(Protocol::MARKER), 403);
        $input = $request->validate(['path' => 'required|string|max:4096', 'method' => 'required|string|max:16']);

        return response()->json(['data' => self::claim($attempt, $input['method'], $input['path'])])
            ->header('Cache-Control', 'no-store');
    }

    public static function fake(Attempt $attempt, Request $request): ?Response
    {
        if (! is_file($attempt->path('route-fakes.json'))) {
            return null;
        }
        $rule = self::claim($attempt, $request->method(), $request->getRequestUri(), true);
        if ($rule === null) {
            return null;
        }
        if (isset($rule['callback'])) {
            $rule = self::invokeCallback($rule['callback'], $request);
        }
        usleep(($rule['delay'] ?? 0) * 1000);
        $headers = $rule['headers'] ?? [];
        if (isset($rule['jsonBody'])) {
            return new JsonResponse($rule['jsonBody'], $rule['status'] ?? 200, $headers, json: true);
        }
        if (isset($rule['status']) || isset($rule['body']) || $headers !== []) {
            return response($rule['body'] ?? '', $rule['status'] ?? 200, $headers);
        }

        return null;
    }

    private static function invokeCallback(array $callback, Request $request): array
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            if (in_array($name, [strtolower(Protocol::MARKER), strtolower(Protocol::CAPABILITY), 'x-rehearsal-fake'], true)) {
                continue;
            }
            foreach ($values as $value) {
                $headers[] = [$name, $value];
            }
        }
        $body = $request->getContent();
        if ($body === '' && preg_match('/^multipart\/form-data\s*;.*?\bboundary=(?:"([^"]+)"|([^;\s]+))/i', $request->header('Content-Type', ''), $match)) {
            $parts = [
                ...self::multipartParts($request->request->all()),
                ...self::multipartParts($request->files->all()),
            ];
            $body = (string) new MultipartStream($parts, $match[1] !== '' ? $match[1] : $match[2]);
            $headers = array_values(array_filter($headers, static fn (array $pair) => $pair[0] !== 'content-length'));
        }
        $response = (new Client)->post('http://127.0.0.1:'.$callback['port'].'/_rehearsal/fake', [
            'headers' => ['x-rehearsal-fake' => $callback['token']],
            'json' => [
                'id' => $callback['id'],
                'url' => $request->getSchemeAndHttpHost().$request->getRequestUri(),
                'method' => $request->method(),
                'headers' => $headers,
                ...($body === '' || in_array($request->method(), ['GET', 'HEAD'], true) ? [] : ['body' => base64_encode($body)]),
            ],
            'allow_redirects' => false,
            'proxy' => '',
            'timeout' => 10,
        ]);

        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function multipartParts(array $values, ?string $prefix = null): array
    {
        $parts = [];
        foreach ($values as $key => $value) {
            $name = $prefix === null ? (string) $key : $prefix.'['.$key.']';
            if (is_array($value)) {
                array_push($parts, ...self::multipartParts($value, $name));
            } elseif ($value instanceof UploadedFile && $value->isValid()) {
                $parts[] = [
                    'name' => $name,
                    'contents' => Utils::tryFopen($value->getPathname(), 'rb'),
                    'filename' => $value->getClientOriginalName(),
                    'headers' => ['Content-Type' => $value->getClientMimeType()],
                ];
            } elseif (is_scalar($value)) {
                $parts[] = ['name' => $name, 'contents' => (string) $value];
            }
        }

        return $parts;
    }

    private static function claim(Attempt $attempt, string $method, string $target, bool $fake = false): ?array
    {
        $path = explode('?', $target, 2)[0];
        if (! str_starts_with($path, '/') || $path === '/_rehearsal' || str_starts_with($path, '/_rehearsal/')) {
            return null;
        }

        return AttemptData::update($attempt, $fake ? 'route-fakes' : 'route-mocks', function (array &$rules) use ($attempt, $method, $path, $target) {
            for ($i = count($rules) - 1; $i >= 0; $i--) {
                $rule = $rules[$i];
                if (($rule['method'] ?? $method) === $method && FakeRoutes::matches($rule['path'], $path, $target)) {
                    if (isset($rule['callback'])) {
                        return ['callback' => $rule['callback']];
                    }
                    $response = $rule['responses'][0];
                    $hasNext = count($rule['responses']) > 1;
                    if (($response['once'] ?? false) || ($response['times'] ?? null) == 1 || ($hasNext && ! isset($response['times']))) {
                        if ($hasNext) {
                            array_shift($rules[$i]['responses']);
                        } else {
                            array_splice($rules, $i, 1);
                        }
                    } elseif (isset($response['times'])) {
                        $rules[$i]['responses'][0]['times']--;
                    }
                    if (isset($response['gate'])) {
                        AttemptData::update($attempt, 'route-gates', static function (array &$gates) use ($response): void {
                            $gates[$response['gate']]['claimed'] = true;
                        });
                    }

                    return $response;
                }
            }

            return null;
        });
    }
}
