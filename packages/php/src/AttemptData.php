<?php

declare(strict_types=1);

namespace Sti3bas\Rehearsal;

use RuntimeException;

final class AttemptData
{
    public static function update(Attempt $attempt, string $name, callable $update): mixed
    {
        return $attempt->run->lock("slot-{$attempt->slot}-{$name}", LOCK_EX, function () use ($attempt, $name, $update) {
            $path = $attempt->path($name.'.json');
            $stored = is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];
            $data = ($stored['attempt'] ?? null) === $attempt->id ? $stored['data'] : [];
            $previous = $data;
            $result = $update($data);
            if ($data !== $previous) {
                $contents = json_encode(['attempt' => $attempt->id, 'data' => $data], JSON_THROW_ON_ERROR);
                if (file_put_contents($path.'.next', $contents) !== strlen($contents) || ! rename($path.'.next', $path)) {
                    throw new RuntimeException('Could not persist Rehearsal attempt data.');
                }
            }

            return $result;
        });
    }
}
