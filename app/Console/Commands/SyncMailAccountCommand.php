<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MailAccount;
use App\Services\Mail\MailSyncService;
use Illuminate\Console\Command;

class SyncMailAccountCommand extends Command
{
    protected $signature = 'mail:sync {account? : ID lub adres e-mail konta pocztowego} {--all : Synchronizuj wszystkie aktywne konta} {--auto : Synchronizuj konta z włączoną automatyczną synchronizacją} {--force : Wymuś synchronizację (ignoruj 5-minutowy interwał)}';

    protected $description = 'Ręczne lub zaplanowane uruchomienie synchronizacji wiadomości rekrutacyjnych dla kont pocztowych.';

    public function handle(MailSyncService $syncService): int
    {
        $target = $this->argument('account');
        $all = (bool) $this->option('all');
        $auto = (bool) $this->option('auto');
        $force = (bool) $this->option('force');

        if ($auto) {
            $intervalMinutes = $force ? 0 : 5;
            $report = $syncService->syncDueAccounts($intervalMinutes);

            if ($report['total_due'] === 0) {
                $this->info('Brak kont oczekujących na automatyczną synchronizację.');

                return self::SUCCESS;
            }

            $this->info(sprintf(
                'Zakończono automatyczną synchronizację: przetworzono %d kont (sukces: %d, błędy: %d).',
                $report['total_due'],
                $report['success_count'],
                $report['failure_count']
            ));

            foreach ($report['results'] as $accountId => $result) {
                if ($result->success) {
                    $this->line(sprintf('  ✓ Konto %s: pobrano %d, zakwalifikowano %d', $accountId, $result->fetchedCount, $result->qualifiedCount));
                } else {
                    $this->error(sprintf('  ✗ Konto %s: %s', $accountId, $result->error));
                }
            }

            return $report['failure_count'] > 0 ? self::FAILURE : self::SUCCESS;
        }

        if ($all) {
            $accounts = MailAccount::whereIn('status', ['active', 'connected'])->get();
            if ($accounts->isEmpty()) {
                $this->warn('Brak aktywnych kont pocztowych do synchronizacji.');

                return self::SUCCESS;
            }

            $this->info(sprintf('Rozpoczynanie synchronizacji dla [%d] aktywnych kont...', $accounts->count()));
            $hasFailure = false;

            foreach ($accounts as $account) {
                $this->line(sprintf('Synchronizacja konta: %s (%s)...', $account->email, $account->provider));
                $result = $syncService->syncAccount($account);

                if ($result->success) {
                    $this->info(sprintf(
                        '  ✓ Sukces: pobrano %d, zakwalifikowano %d, zignorowano %d, niedopasowane %d.',
                        $result->fetchedCount,
                        $result->qualifiedCount,
                        $result->ignoredCount,
                        $result->unmatchedCount
                    ));
                } else {
                    $hasFailure = true;
                    $this->error(sprintf('  ✗ Błąd: %s', $result->error));
                }
            }

            return $hasFailure ? self::FAILURE : self::SUCCESS;
        }

        if (! empty($target)) {
            $account = MailAccount::where('id', $target)->orWhere('email', $target)->first();
            if (! $account) {
                $this->error(sprintf('Nie znaleziono konta pocztowego dla identyfikatora: [%s].', $target));

                return self::FAILURE;
            }

            $this->info(sprintf('Rozpoczynanie synchronizacji konta: %s (%s)...', $account->email, $account->provider));
            $result = $syncService->syncAccount($account);

            if ($result->success) {
                $this->info('Synchronizacja zakończona sukcesem!');
                $this->table(
                    ['Pobrano', 'Zakwalifikowano', 'Zignorowano', 'Niedopasowane'],
                    [[
                        $result->fetchedCount,
                        $result->qualifiedCount,
                        $result->ignoredCount,
                        $result->unmatchedCount,
                    ]]
                );

                return self::SUCCESS;
            }

            $this->error(sprintf('Synchronizacja nie powiodła się: %s', $result->error));

            return self::FAILURE;
        }

        // Neither argument nor --all nor --auto was provided
        $this->warn('Nie wskazano konta. Podaj adres e-mail, ID konta lub flagę --auto / --all:');
        $this->line('  php artisan mail:sync <email>');
        $this->line('  php artisan mail:sync --auto');
        $this->line('  php artisan mail:sync --all');

        $accounts = MailAccount::all();
        if ($accounts->isNotEmpty()) {
            $this->line("\nDostępne konta pocztowe w bazie:");
            $this->table(
                ['ID', 'E-mail', 'Dostawca', 'Typ uwierzytelnienia', 'Status'],
                $accounts->map(fn ($acc) => [
                    $acc->id,
                    $acc->email,
                    $acc->provider,
                    $acc->auth_type,
                    $acc->status,
                ])->toArray()
            );
        }

        return self::INVALID;
    }
}
