<?php

declare(strict_types=1);

use Skylence\Shardwise\Shard;
use Skylence\Shardwise\ShardContext;
use Skylence\Shardwise\Uuid\ShardAwareUuid;
use Skylence\Shardwise\Uuid\ShardAwareUuidFactory;

beforeEach(function (): void {
    ShardContext::clear();
    $this->factory = new ShardAwareUuidFactory(shardBits: 10, embedMetadata: true);
});

it('generates a uuid without shard context', function (): void {
    $uuid = $this->factory->generate();

    expect($uuid)->toBeInstanceOf(ShardAwareUuid::class)
        ->and($uuid->toString())->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i');
});

it('generates a uuid with shard context', function (): void {
    $shard = Shard::fromConfig('1', ['name' => 'Shard 1']);

    $uuid = $this->factory->generate($shard);

    expect($uuid)->toBeInstanceOf(ShardAwareUuid::class)
        ->and($uuid->hasShardId())->toBeTrue()
        ->and($uuid->getShardId())->toBe(1);
});

it('embeds shard id from named shard', function (): void {
    $shard = Shard::fromConfig('shard-5', ['name' => 'Shard 5']);

    $uuid = $this->factory->generate($shard);

    expect($uuid->hasShardId())->toBeTrue()
        ->and($uuid->getShardId())->toBe(5); // Extracts numeric part from "shard-5"
});

it('generates string uuid', function (): void {
    $uuidString = $this->factory->generateString();

    expect($uuidString)->toBeString()
        ->and($uuidString)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i');
});

it('parses a uuid and extracts shard id', function (): void {
    $shard = Shard::fromConfig('3', ['name' => 'Shard 3']);
    $originalUuid = $this->factory->generate($shard);

    $parsed = $this->factory->parse($originalUuid->toString());

    expect($parsed->getShardId())->toBe(3);
});

it('uses current shard context when generating', function (): void {
    $shard = Shard::fromConfig('7', ['name' => 'Shard 7']);
    ShardContext::push($shard);

    $uuid = $this->factory->generate();

    expect($uuid->getShardId())->toBe(7);
});

it('extracts shard id from uuid string', function (): void {
    $shard = Shard::fromConfig('9', ['name' => 'Shard 9']);
    $uuid = $this->factory->generate($shard);

    $extractedId = $this->factory->extractShardIdFromUuid($uuid->toString());

    expect($extractedId)->toBe(9);
});

it('respects max shard id based on bits', function (): void {
    // 10 bits = max 1023
    expect($this->factory->getMaxShardId())->toBe(1023);

    $factory8bit = new ShardAwareUuidFactory(shardBits: 8, embedMetadata: true);
    expect($factory8bit->getMaxShardId())->toBe(255);
});

it('does not embed metadata when disabled', function (): void {
    $factory = new ShardAwareUuidFactory(shardBits: 10, embedMetadata: false);
    $shard = Shard::fromConfig('5', ['name' => 'Shard 5']);

    $uuid = $factory->generate($shard);

    expect($uuid->hasShardId())->toBeFalse();
});

it('uuid has equality check', function (): void {
    $uuid1 = $this->factory->generate();
    $uuid2 = $this->factory->parse($uuid1->toString());

    expect($uuid1->equals($uuid2))->toBeTrue()
        ->and($uuid1->equals($uuid1->toString()))->toBeTrue();
});

it('uuid can convert to string', function (): void {
    $uuid = $this->factory->generate();

    expect((string) $uuid)->toBe($uuid->toString());
});

it('uuid provides hex representation', function (): void {
    $uuid = $this->factory->generate();

    $hex = $uuid->getHex();

    expect($hex)->toBeString()
        ->and(strlen($hex))->toBe(32);
});

it('uuid provides bytes', function (): void {
    $uuid = $this->factory->generate();

    $bytes = $uuid->getBytes();

    expect(strlen($bytes))->toBe(16);
});
