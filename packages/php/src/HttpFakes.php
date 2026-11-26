<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class HttpFakes
{
    public static function register(Attempt $attempt, array $input): void
    {
        abort_if(strlen(json_encode($input, JSON_THROW_ON_ERROR)) > 256_000, 422);
        $data = Validator::validate($input, [
            'rules' => 'present|array|list|max:64',
            'rules.*' => 'required|array:url,method,responses',
            'rules.*.url' => ['required', 'string', 'max:1024', 'regex:~\Ahttps?://[^/*?#\s@]+(?:/[^#\s]*)?\z~'],
            'rules.*.method' => ['sometimes', 'string', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'])],
            'rules.*.responses' => 'required|array|list|min:1|max:20',
            'rules.*.responses.*' => 'present|array:status,jsonBody,body,headers,networkError',
            'rules.*.responses.*.status' => 'sometimes|integer|min:200|max:599',
            'rules.*.responses.*.jsonBody' => 'sometimes|string|json|max:100000',
            'rules.*.responses.*.body' => 'sometimes|string|max:100000',
            'rules.*.responses.*.headers' => 'sometimes|array|max:20',
            'rules.*.responses.*.headers.*' => 'required|string|max:1024',
            'rules.*.responses.*.networkError' => 'sometimes|boolean',
            'preventStrayRequests' => 'sometimes|boolean',
        ]);
        foreach ($data['rules'] as $rule) {
            foreach ($rule['responses'] as $response) {
                abort_if(isset($response['body'], $response['jsonBody']), 422);
                foreach ($response['headers'] ?? [] as $name => $value) {
                    abort_unless(preg_match('/\A[a-zA-Z0-9!#$%&\'*+.^_`|~-]+\z/', $name)
                        && ! preg_match('/[\r\n]/', $value), 422);
                }
            }
        }
        AttemptData::update($attempt, 'http-fakes', static function (array &$state) use ($data): void {
            $state = $data;
        });
    }

    public static function bind(Attempt $attempt): void
    {
        if (! is_file($attempt->path('http-fakes.json'))) {
            return;
        }
        $state = AttemptData::update($attempt, 'http-fakes', static fn (array $state): array => $state);
        if (! $state) {
            return;
        }
        if ($state['preventStrayRequests'] ?? true) {
            Http::preventStrayRequests();
        }
        Http::fake(static function (Request $request) use ($attempt) {
            $response = AttemptData::update($attempt, 'http-fakes', static function (array &$state) use ($request): ?array {
                foreach (array_reverse($state['rules'], true) as $i => $rule) {
                    if (($rule['method'] ?? $request->method()) !== $request->method() || ! Str::is($rule['url'], $request->url())) {
                        continue;
                    }
                    if (count($rule['responses']) > 1) {
                        return array_shift($state['rules'][$i]['responses']);
                    }

                    return $rule['responses'][0];
                }

                return null;
            });
            if ($response === null) {
                return null;
            }
            if ($response['networkError'] ?? false) {
                return Http::failedConnection('Rehearsal simulated a connection failure.');
            }
            $headers = $response['headers'] ?? [];
            if (isset($response['jsonBody']) && ! array_filter(array_keys($headers), static fn ($key) => strtolower($key) === 'content-type')) {
                $headers['Content-Type'] = 'application/json';
            }

            return Http::response($response['jsonBody'] ?? $response['body'] ?? '', $response['status'] ?? 200, $headers);
        });
    }
}
