<?php
// [Profiler.php] — Minimal Drop-in Profiler

class Profiler
{
    private static array $marks = [];
    private static float $start;
    private static bool $enabled = true;

    public static function init(bool $enabled = true): void
    {
        self::$enabled = $enabled;
        self::$start = microtime(true);
        self::$marks = [
            ['label' => 'START', 'time' => self::$start]
        ];
    }

    public static function mark(string $label): void
    {
        if (!self::$enabled) return;

        self::$marks[] = [
            'label' => $label,
            'time' => microtime(true)
        ];
    }

    public static function report(): void
    {
        if (!self::$enabled) return;

        $end = microtime(true);
        $last = self::$start;

        echo "<pre style='position:fixed;bottom:0;left:0;right:0;background:#111;color:#0f0;padding:10px;font-size:12px;z-index:99999;'>";

        foreach (self::$marks as $mark) {
            $delta = $mark['time'] - $last;
            $total = $mark['time'] - self::$start;

            printf(
                "%-25s | +%0.4fs | %0.4fs\n",
                $mark['label'],
                $delta,
                $total
            );

            $last = $mark['time'];
        }

        printf("\nTOTAL: %0.4fs\n", $end - self::$start);

        echo "</pre>";
    }
}