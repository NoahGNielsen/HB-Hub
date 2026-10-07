<?php
// Blocking the Gambling and Games pages, picked on the settings page and kept in userSettings.block
// ({mode, pendingMode, pendingFrom}, see database/defaultUserSettings.json).
//
// A stricter block starts straight away. A less strict one waits, so it can't just be switched off in the moment:
// switched off during school hours it waits until school is over, and switched off from "All the time" it waits 6 hours.
// Until then the old block stays, with the new one in pendingMode from the time in pendingFrom (a Unix time).
require_once __DIR__ . '/userSettings.php';

const HBHUB_BLOCK_NONE = 'none';
const HBHUB_BLOCK_SCHOOL = 'school';
const HBHUB_BLOCK_ALWAYS = 'always';
const HBHUB_BLOCK_MODES = [ // least strict first
    HBHUB_BLOCK_NONE => 'No block',
    HBHUB_BLOCK_SCHOOL => 'During school hours (8 AM - 3 PM)',
    HBHUB_BLOCK_ALWAYS => 'All the time',
];
const HBHUB_BLOCK_TIME_ZONE = 'Europe/Copenhagen'; // school hours are in the school's time, not the server's
const HBHUB_BLOCK_SCHOOL_START = 8; // 8 AM
const HBHUB_BLOCK_SCHOOL_END = 15; // 3 PM
const HBHUB_BLOCK_ALWAYS_DELAY = 6 * 3600; // seconds before switching off "All the time" takes effect

/**
 * The user's block, with a waiting change applied once its time has come.
 *
 * @return array{mode: string, pendingMode: ?string, pendingFrom: ?int}
 */
function hbHubBlockSettings(array $userSettings, ?int $now = null): array
{
    $now ??= time();
    $saved = is_array($userSettings['block'] ?? null) ? $userSettings['block'] : [];

    $mode = hbHubBlockIsMode($saved['mode'] ?? null) ? $saved['mode'] : HBHUB_BLOCK_NONE;
    $pendingMode = hbHubBlockIsMode($saved['pendingMode'] ?? null) ? $saved['pendingMode'] : null;
    $pendingFrom = is_int($saved['pendingFrom'] ?? null) ? $saved['pendingFrom'] : null;

    if ($pendingMode === null || $pendingFrom === null) {
        return ['mode' => $mode, 'pendingMode' => null, 'pendingFrom' => null];
    }
    if ($pendingFrom <= $now) {
        return ['mode' => $pendingMode, 'pendingMode' => null, 'pendingFrom' => null];
    }
    return ['mode' => $mode, 'pendingMode' => $pendingMode, 'pendingFrom' => $pendingFrom];
}

function hbHubBlockIsMode(mixed $mode): bool
{
    return is_string($mode) && array_key_exists($mode, HBHUB_BLOCK_MODES);
}

/**
 * How strict the block is: 0 for no block, higher is stricter.
 */
function hbHubBlockStrictness(string $mode): int
{
    return (int) array_search($mode, array_keys(HBHUB_BLOCK_MODES), true);
}

function hbHubBlockLocalTime(int $time): DateTimeImmutable
{
    return (new DateTimeImmutable('@' . $time))->setTimezone(new DateTimeZone(HBHUB_BLOCK_TIME_ZONE));
}

/**
 * Whether it's school hours: Monday to Friday, 8 AM to 3 PM.
 */
function hbHubBlockIsSchoolHours(int $time): bool
{
    $local = hbHubBlockLocalTime($time);
    $hour = (int) $local->format('G');
    return (int) $local->format('N') <= 5 && $hour >= HBHUB_BLOCK_SCHOOL_START && $hour < HBHUB_BLOCK_SCHOOL_END;
}

/**
 * Whether Gambling and Games are blocked right now.
 */
function hbHubBlockIsActive(array $block, ?int $now = null): bool
{
    return match ($block['mode']) {
        HBHUB_BLOCK_ALWAYS => true,
        HBHUB_BLOCK_SCHOOL => hbHubBlockIsSchoolHours($now ?? time()),
        default => false,
    };
}

/**
 * The block after the user picked $newMode: stricter (or the same) right away, less strict after the wait.
 */
function hbHubBlockChange(array $block, string $newMode, ?int $now = null): array
{
    $now ??= time();

    if (hbHubBlockStrictness($newMode) >= hbHubBlockStrictness($block['mode'])) {
        return ['mode' => $newMode, 'pendingMode' => null, 'pendingFrom' => null];
    }
    if ($block['pendingMode'] === $newMode) {
        return $block; // already waiting for this one, so the wait doesn't start over
    }

    $from = match ($block['mode']) {
        HBHUB_BLOCK_ALWAYS => $now + HBHUB_BLOCK_ALWAYS_DELAY,
        HBHUB_BLOCK_SCHOOL => hbHubBlockIsSchoolHours($now)
            ? hbHubBlockLocalTime($now)->setTime(HBHUB_BLOCK_SCHOOL_END, 0)->getTimestamp()
            : $now,
    };
    if ($from <= $now) {
        return ['mode' => $newMode, 'pendingMode' => null, 'pendingFrom' => null];
    }
    return ['mode' => $block['mode'], 'pendingMode' => $newMode, 'pendingFrom' => $from];
}

/**
 * A time as "3:00 PM today", "9:41 AM tomorrow" or "9:41 AM on Thursday", in the school's time zone.
 */
function hbHubBlockFormatTime(int $time, ?int $now = null): string
{
    $local = hbHubBlockLocalTime($time);
    $today = hbHubBlockLocalTime($now ?? time())->setTime(0, 0);
    $day = match ((int) $today->diff($local->setTime(0, 0))->format('%r%a')) {
        0 => 'today',
        1 => 'tomorrow',
        default => 'on ' . $local->format('l'),
    };
    return $local->format('g:i A') . ' ' . $day;
}

/**
 * For the Gambling and Games pages, right after session.php: when the user has them blocked right now,
 * shows the "blocked" page instead and stops.
 */
function hbHubBlockPage(array $user, string $section): void
{
    $block = hbHubBlockSettings(hbHubUserSettings($user['userSettings'] ?? null));
    if (!hbHubBlockIsActive($block)) {
        return;
    }

    $blockSection = $section;
    $blockIsSchool = $block['mode'] === HBHUB_BLOCK_SCHOOL;
    require __DIR__ . '/blockedPage.php';
    exit;
}
