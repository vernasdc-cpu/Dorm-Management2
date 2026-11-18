<?php

function allocation_room_status(string $storedStatus, int $occupied, int $reserved, int $capacity): string {
    if (in_array($storedStatus, ['Under Maintenance', 'Unavailable'], true)) return $storedStatus;
    if ($occupied >= max(1, $capacity)) return 'Fully Occupied';
    if ($occupied > 0) return 'Partially Occupied';
    if ($reserved > 0 || $storedStatus === 'Reserved') return 'Reserved';
    return 'Available';
}
