<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CategoryType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Category extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['type' => CategoryType::class];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('name');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** @return list<int> This category plus its direct children. */
    public function familyIds(): array
    {
        return [$this->id, ...$this->children()->pluck('id')->all()];
    }

    /**
     * Options for <select> elements, children indented under their parent.
     *
     * @return Collection<int, array{id:int,label:string,type:string}>
     */
    public static function options(?CategoryType $type = null): Collection
    {
        $all = self::query()
            ->when($type, fn ($q) => $q->where('type', $type->value))
            ->orderBy('name')
            ->get();

        return $all->whereNull('parent_id')->flatMap(function (self $parent) use ($all) {
            $rows = [['id' => $parent->id, 'label' => $parent->name, 'type' => $parent->type->value]];
            foreach ($all->where('parent_id', $parent->id) as $child) {
                $rows[] = ['id' => $child->id, 'label' => '— '.$child->name, 'type' => $child->type->value];
            }

            return $rows;
        })->values();
    }
}
