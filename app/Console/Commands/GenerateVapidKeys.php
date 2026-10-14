<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\PushService;
use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Create the VAPID key pair that lets this application push to a browser.
 *
 * Run once. The pair is the sender identity: the push service uses it to
 * check that a message claims an endpoint it issued, and the browser checks
 * it before waking a device. Because it is an identity rather than a
 * credential that can be rotated freely, regenerating it invalidates every
 * subscription already held - the push services will reject messages signed
 * with a key they have not seen before, so every member would silently stop
 * receiving pushes until they came back to the app and re-subscribed.
 *
 * That is why regeneration is refused unless it is asked for explicitly.
 */
class GenerateVapidKeys extends Command
{
    protected $signature = 'ourmonie:generate-vapid
        {--force : Regenerate even though keys already exist, breaking every saved subscription}
        {--subject= : Who push complaints should reach - a mailto: or https: URL}';

    protected $description = 'Generate the VAPID key pair used to sign web push messages';

    public function handle(): int
    {
        $existing = PushService::publicKey();

        if ($existing !== null && ! $this->option('force')) {
            $this->error('VAPID keys already exist. Regenerating breaks every saved subscription.');
            $this->line('');
            $this->line('Current public key: '.$existing);
            $this->line('Pass --force if you intend to invalidate all of them.');

            return self::FAILURE;
        }

        if ($existing !== null && $this->option('force') && ! $this->confirm('Every member will stop receiving pushes until they re-subscribe. Continue?')) {
            $this->line('Left untouched.');

            return self::SUCCESS;
        }

        $keys = VAPID::createVapidKeys();

        Setting::put('public_key', $keys['publicKey'], 'push');
        Setting::put('private_key', $keys['privateKey'], 'push');

        $subject = trim((string) $this->option('subject'));

        if ($subject !== '') {
            Setting::put('subject', $subject, 'push');
        }

        $this->newLine();
        $this->info('VAPID keys generated.');
        $this->newLine();
        $this->line('Public key (this one goes to the browser):');
        $this->line('  '.$keys['publicKey']);
        $this->newLine();
        $this->line('Private key (stored in settings, never leaves the server):');
        $this->line('  '.str_repeat('•', 20).'…'.substr($keys['privateKey'], -4));
        $this->newLine();
        $this->line('Complaints reach: '.(Setting::getValue('push.subject', 'mailto:support@africanolympiads.com')));

        return self::SUCCESS;
    }
}
