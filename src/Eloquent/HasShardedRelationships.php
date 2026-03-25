<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Eloquent;

use Skylence\Shardwise\Eloquent\Relations\CrossShardHasMany;
use Skylence\Shardwise\Eloquent\Relations\CrossShardHasOne;

/**
 * Trait for central models that need relationships to sharded models.
 *
 * Use this trait alongside CentralModel when a central database model
 * needs to define hasMany or hasOne relationships to models that live
 * on sharded databases. Standard Eloquent relationships would query
 * the central database where the sharded records do not exist.
 *
 * Instead of returning Eloquent Relation objects, these methods return
 * cross-shard relation objects that query across all active shards.
 *
 * Example:
 *
 *     class Agent extends Model
 *     {
 *         use CentralModel, HasShardedRelationships;
 *
 *         public function tickets(): CrossShardHasMany
 *         {
 *             return $this->hasManyAcrossShards(Ticket::class, 'agent_id');
 *         }
 *
 *         public function activeSession(): CrossShardHasOne
 *         {
 *             return $this->hasOneAcrossShards(Session::class, 'agent_id');
 *         }
 *     }
 *
 *     // Usage:
 *     $agent->tickets()->get();
 *     $agent->tickets()->where('status', 'open')->count();
 *     $agent->tickets()->orderBy('created_at', 'desc')->limit(10)->get();
 *     $agent->activeSession()->get();
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait HasShardedRelationships
{
    /**
     * Define a cross-shard one-to-many relationship.
     *
     * Use this instead of hasMany() when the related model is sharded.
     * The returned object supports fluent chaining with where(), orderBy(),
     * limit(), and other query modifiers.
     *
     * @template TRelated of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TRelated>  $related  The sharded model class.
     * @param  string|null  $foreignKey  The foreign key on the sharded model. Defaults to this model's foreign key convention.
     * @param  string|null  $localKey  The local key on this model. Defaults to the primary key.
     * @return CrossShardHasMany<TRelated>
     */
    public function hasManyAcrossShards(string $related, ?string $foreignKey = null, ?string $localKey = null): CrossShardHasMany
    {
        $foreignKey ??= $this->getForeignKey();
        $localKey ??= $this->getKeyName();

        return new CrossShardHasMany($this, $related, $foreignKey, $localKey);
    }

    /**
     * Define a cross-shard one-to-one relationship.
     *
     * Use this instead of hasOne() when the related model is sharded.
     * The returned object short-circuits on the first shard that
     * contains the related model.
     *
     * @template TRelated of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TRelated>  $related  The sharded model class.
     * @param  string|null  $foreignKey  The foreign key on the sharded model. Defaults to this model's foreign key convention.
     * @param  string|null  $localKey  The local key on this model. Defaults to the primary key.
     * @return CrossShardHasOne<TRelated>
     */
    public function hasOneAcrossShards(string $related, ?string $foreignKey = null, ?string $localKey = null): CrossShardHasOne
    {
        $foreignKey ??= $this->getForeignKey();
        $localKey ??= $this->getKeyName();

        return new CrossShardHasOne($this, $related, $foreignKey, $localKey);
    }
}
