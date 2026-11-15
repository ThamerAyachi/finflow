<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Category;
use Illuminate\Support\Facades\DB;

/** A ready-made category tree, so a fresh install is usable immediately. */
final class StarterCategories
{
    /** type => [parent => [icon, colour, [child => icon]]] */
    public const TREE = [
        'expense' => [
            'Food & Dining' => ['utensils', '#F59E0B', ['Groceries' => 'shopping-cart', 'Restaurants' => 'utensils', 'Coffee' => 'coffee']],
            'Transport' => ['car', '#3B82F6', ['Fuel' => 'fuel', 'Taxi & Rides' => 'car-taxi-front']],
            'Housing' => ['house', '#8B5CF6', ['Rent' => 'house', 'Utilities' => 'zap', 'Internet & Phone' => 'wifi']],
            'Shopping' => ['shopping-bag', '#EC4899', []],
            'Health' => ['heart-pulse', '#14B8A6', []],
            'Entertainment' => ['clapperboard', '#06B6D4', ['Subscriptions' => 'tv']],
            'Insurance' => ['shield', '#64748B', []],
            'Business Ops' => ['briefcase', '#F97316', [
                'Advertising' => 'megaphone', 'Inventory' => 'package', 'Shipping' => 'truck',
                'Software & SaaS' => 'laptop', 'Packaging' => 'box',
            ]],
        ],
        'income' => [
            'Salary' => ['briefcase', '#22C55E', []],
            'Freelance' => ['laptop', '#10B981', []],
            'Sales Revenue' => ['store', '#34D399', []],
            'Other Income' => ['gift', '#84CC16', []],
        ],
    ];

    /**
     * Create every starter category that doesn't exist yet (matched by name and type).
     *
     * @return array<string, Category> every category in the tree, keyed by name
     */
    public static function install(): array
    {
        return DB::transaction(function (): array {
            $byName = [];

            foreach (self::TREE as $type => $parents) {
                foreach ($parents as $name => [$icon, $color, $children]) {
                    $parent = $byName[$name] = Category::query()->firstOrCreate(
                        ['name' => $name, 'type' => $type, 'parent_id' => null],
                        ['icon' => $icon, 'color_hex' => $color],
                    );

                    foreach ($children as $childName => $childIcon) {
                        $byName[$childName] = Category::query()->firstOrCreate(
                            ['name' => $childName, 'type' => $type, 'parent_id' => $parent->id],
                            ['icon' => $childIcon, 'color_hex' => $color],
                        );
                    }
                }
            }

            return $byName;
        });
    }
}
