<?php

namespace Database\Seeders;

use App\Models\Menu\Category;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CategoryItemSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $menuData = [
            [
                "section" => "Breakfast",
                "items" => [
                    ["name" => "Pancakes", "price" => 8000, "currency" => "TZS", "description" => "Fluffy pancakes served with syrup."],
                    ["name" => "Omelette", "price" => 7000, "currency" => "TZS", "description" => "Three-egg omelette with cheese and veggies."],
                    ["name" => "Fruit Bowl", "price" => 5000, "currency" => "TZS", "description" => "Seasonal fresh fruits."],
                ],
            ],
            [
                "section" => "Lunch & Dinner",
                "items" => [
                    ["name" => "Grilled Chicken", "price" => 15000, "currency" => "TZS", "description" => "Served with mashed potatoes and salad."],
                    ["name" => "Vegetable Pasta", "price" => 12000, "currency" => "TZS", "description" => "Pasta tossed with fresh vegetables."],
                    ["name" => "Steak", "price" => 22000, "currency" => "TZS", "description" => "Juicy steak cooked to your liking."],
                ],
            ],
            [
                "section" => "Salads",
                "items" => [
                    ["name" => "Caesar Salad", "price" => 9000, "currency" => "TZS", "description" => "Classic Caesar with croutons and parmesan."],
                    ["name" => "Greek Salad", "price" => 10000, "currency" => "TZS", "description" => "Tomatoes, cucumbers, olives, feta cheese."],
                ],
            ],
            [
                "section" => "Drinks",
                "items" => [
                    ["name" => "Coffee", "price" => 3000, "currency" => "TZS", "description" => "Freshly brewed hot coffee."],
                    ["name" => "Orange Juice", "price" => 4000, "currency" => "TZS", "description" => "Fresh squeezed orange juice."],
                    ["name" => "Smoothie", "price" => 5000, "currency" => "TZS", "description" => "Mixed fruit smoothie."],
                ],
            ],
            [
                "section" => "Fruits",
                "items" => [
                    ["name" => "Apple", "price" => 2000, "currency" => "TZS", "description" => "Crisp and sweet."],
                    ["name" => "Banana", "price" => 2000, "currency" => "TZS", "description" => "Rich in potassium."],
                ],
            ],
            [
                "section" => "Dessert",
                "items" => [
                    ["name" => "Chocolate Cake", "price" => 6000, "currency" => "TZS", "description" => "Rich chocolate layered cake."],
                    ["name" => "Ice Cream", "price" => 5000, "currency" => "TZS", "description" => "Choice of vanilla, chocolate, or strawberry."],
                ],
            ],
            [
                "section" => "Soups",
                "items" => [
                    ["name" => "Tomato Soup", "price" => 7000, "currency" => "TZS", "description" => "Creamy tomato soup with basil."],
                    ["name" => "Chicken Soup", "price" => 8000, "currency" => "TZS", "description" => "Classic chicken noodle soup."],
                ],
            ],
            [
                "section" => "Appetizers",
                "items" => [
                    ["name" => "Spring Rolls", "price" => 5000, "currency" => "TZS", "description" => "Crispy vegetable spring rolls."],
                    ["name" => "Garlic Bread", "price" => 4000, "currency" => "TZS", "description" => "Toasted bread with garlic butter."],
                ],
            ],
            [
                "section" => "Grill & Barbecue",
                "items" => [
                    ["name" => "BBQ Ribs", "price" => 18000, "currency" => "TZS", "description" => "Slow-cooked ribs with BBQ sauce."],
                    ["name" => "Grilled Veggies", "price" => 10000, "currency" => "TZS", "description" => "Assorted grilled vegetables."],
                ],
            ],
            [
                "section" => "Seafood",
                "items" => [
                    ["name" => "Grilled Fish", "price" => 16000, "currency" => "TZS", "description" => "Fresh fish grilled to perfection."],
                    ["name" => "Prawns", "price" => 20000, "currency" => "TZS", "description" => "Succulent prawns with garlic butter."],
                ],
            ],
            [
                "section" => "Vegetarian",
                "items" => [
                    ["name" => "Vegetable Curry", "price" => 11000, "currency" => "TZS", "description" => "Mixed vegetables in a spicy curry sauce."],
                    ["name" => "Tofu Stir Fry", "price" => 10000, "currency" => "TZS", "description" => "Tofu and vegetables stir-fried in soy sauce."],
                ],
            ],
            [
                "section" => "Kids Menu",
                "items" => [
                    ["name" => "Mini Burger", "price" => 6000, "currency" => "TZS", "description" => "Small beef burger with fries."],
                    ["name" => "Chicken Nuggets", "price" => 5000, "currency" => "TZS", "description" => "Crispy chicken nuggets."],
                ],
            ],
            [
                "section" => "Specials",
                "items" => [
                    ["name" => "Chef's Special", "price" => 25000, "currency" => "TZS", "description" => "Ask your server for today's special."],
                ],
            ],
            [
                "section" => "Chef's Choice",
                "items" => [
                    ["name" => "Signature Dish", "price" => 30000, "currency" => "TZS", "description" => "Exclusive dish by our chef."],
                ],
            ],
        ];


        foreach ($menuData as $menu) {
            // Create category
            $category = Category::query()->create([
                'name' => $menu['section'],
                'business_id' => 1,
            ]);

            // Loop through items in that section
            foreach ($menu['items'] as $item) {
                // Random discount between 20–80
                $discount = rand(20, 80);

                // Calculate final price
                $final_price = ($discount > 0)
                    ? round($item['price'] - ($item['price'] * ($discount / 100)))
                    : $item['price'];

                // Create item with discount and final price
                $category->items()->create([
                    'name' => $item['name'],
                    'price' => $item['price'],
                    'currency' => $item['currency'],
                    'description' => $item['description'],
                    'discount' => $discount,
                    'final_price' => $final_price,
                ]);
            }
        }
    }
}
