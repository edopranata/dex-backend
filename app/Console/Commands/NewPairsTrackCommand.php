<?php

namespace App\Console\Commands;

use App\Services\NewPairs\NewPairTracker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('newpairs:track')]
#[Description('Token Baru: catat pool muda (snapshot DEX Screener, candle 5 menit, trade kertas); hanya pencatatan')]
class NewPairsTrackCommand extends Command
{
    public function handle(NewPairTracker $tracker): int
    {
        $result = $tracker->run();

        $this->components->info(sprintf(
            'Token Baru: %d baru, %d disampel, %d trade dibuka, %d ditutup, %d selesai dipantau%s.',
            $result['registered'], $result['sampled'], $result['opened'], $result['closed'], $result['ended'],
            $result['security'] !== null ? ", keamanan {$result['security']}" : '',
        ));

        return self::SUCCESS;
    }
}
