<?php

namespace App\Enum;

enum AssistantActionType: string
{
    case STOCK_ADD = 'stock.add';
    case STOCK_UPDATE = 'stock.update';
    case STOCK_REMOVE = 'stock.remove';

    case RECIPE_ADD = 'recipe.add';
    case RECIPE_UPDATE = 'recipe.update';

    case SHOPPING_ADD = 'shopping.add';
    case SHOPPING_UPDATE = 'shopping.update';
    case SHOPPING_REMOVE = 'shopping.remove';

    case MEAL_PLAN_PLAN = 'meal_plan.plan';
    case MEAL_PLAN_UNPLAN = 'meal_plan.unplan';
}