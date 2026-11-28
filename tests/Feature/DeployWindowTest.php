<?php

use App\Jobs\SyncTokenCandles;
use App\Models\TaskRun;
use App\Models\Token;
use Illuminate\Support\Facades\Queue;

test('a deploy waits for no running task and no schedule due soon, and goes ahead while the market-data queue holds jobs', function () {
    Queue::fake();
    $this->travelTo('2026-11-16 10:28:00');   // the next task due is the :30 cycle, 2 minutes away

    $this->artisan('app:deploy-window', ['--wait' => 0])->assertSuccessful();

    $this->travelTo('2026-11-16 10:29:00');   // the :30 cycle is due within the 90 s margin
    $this->artisan('app:deploy-window', ['--wait' => 0])->assertFailed();

    // GeckoTerminal keeps the queue busy most of every 15 minutes; waiting jobs stay in Redis during the deploy.
    $this->travelTo('2026-11-16 10:28:00');
    SyncTokenCandles::dispatch(Token::factory()->create(), []);
    $this->artisan('app:deploy-window', ['--wait' => 0])->assertSuccessful();
});

test('a running admin task also delays the deploy', function () {
    Queue::fake();
    $this->travelTo('2026-11-16 10:58:00');
    TaskRun::query()->create(['command' => 'market:backfill', 'trigger' => 'admin', 'status' => 'running', 'started_at' => now()]);

    $this->artisan('app:deploy-window', ['--wait' => 0])->assertFailed();
});

test('the schedule keeps two deploy windows an hour free of GeckoTerminal work', function () {
    $this->travelTo('2026-11-16 10:57:00');
    Queue::fake();

    $this->artisan('app:deploy-window', ['--wait' => 0])->assertSuccessful();
});
