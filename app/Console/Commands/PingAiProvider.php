<?php

namespace App\Console\Commands;

use App\Support\Ai\AiException;
use App\Support\Ai\AiManager;
use Illuminate\Console\Command;
use Throwable;

/**
 * Proves the configured AI provider answers, before a lecturer depends on it.
 *
 * Sends one harmless request (no student data, nothing from the database) and
 * reports which provider and model answered and how long it took, or the reason
 * it did not. The reason has been scrubbed of credentials by the driver, and
 * this command prints no configuration value that could be one: the key is
 * neither shown nor echoed, only whether it is set.
 *
 * Bypasses the per-lecturer daily limit, since there is no lecturer, and writes
 * nothing to the database.
 */
class PingAiProvider extends Command
{
    protected $signature = 'ai:ping';

    protected $description = 'Send a harmless test request to the configured AI provider and report the result';

    public function handle(AiManager $manager): int
    {
        $current = $manager->current();

        $this->line('Driver     '.$manager->driverName());
        $this->line('Provider   '.$current['label']);
        $this->line('Model      '.($current['model'] !== '' ? $current['model'] : '(not set)'));

        if ($reason = $manager->reason()) {
            $this->newLine();
            $this->warn($reason);
            $this->line('Set the keys for the provider in .env (see .env.example), then run this again.');

            return $manager->driverName() === 'none' ? self::SUCCESS : self::FAILURE;
        }

        $started = hrtime(true);

        try {
            $answer = $manager->provider()->complete(
                'This is a connectivity check. Reply with ok set to true.',
                ['check' => 'connectivity'],
                [
                    'type' => 'object',
                    'required' => ['ok'],
                    'properties' => ['ok' => ['type' => 'boolean']],
                    'additionalProperties' => false,
                ],
            );
        } catch (AiException $e) {
            return $this->failed($started, $e->getMessage());
        } catch (Throwable $e) {
            return $this->failed($started, 'Unexpected '.class_basename($e).' while calling the provider.');
        }

        $ms = $this->elapsed($started);

        $this->newLine();

        if (($answer['ok'] ?? false) !== true) {
            $this->warn("The provider answered in {$ms} ms, but not as asked.");

            return self::FAILURE;
        }

        $this->info("OK. {$current['label']} answered in {$ms} ms.");

        return self::SUCCESS;
    }

    private function failed(int $started, string $message): int
    {
        $this->newLine();
        $this->error('Failed after '.$this->elapsed($started).' ms.');
        $this->line($message);

        return self::FAILURE;
    }

    private function elapsed(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
