<?php

namespace App\Recruitment;

use App\Integrations\IntegrationException;
use App\Models\Recruitment\LocalCommand;
use Illuminate\Support\Facades\DB;

class CommandRunner
{
    public function run(string $id, string $type, array $payload, callable $action): array
    {
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($id, $type, $hash, $action) {
            $command = LocalCommand::find($id);
            if ($command) {
                if ($command->type !== $type || $command->payload_hash !== $hash) {
                    throw new IntegrationException('Identyfikator operacji został użyty dla innej zmiany.', 'command_mismatch');
                }

                return $command->result ?? [];
            }
            $command = LocalCommand::create(['id' => $id, 'type' => $type, 'payload_hash' => $hash]);
            $result = $action();
            $command->update(['result' => $result]);

            return $result;
        }, 5);
    }
}
