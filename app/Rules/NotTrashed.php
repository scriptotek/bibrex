<?php

namespace App\Rules;

use App\Item;
use Illuminate\Contracts\Validation\Rule;

class NotTrashed implements Rule
{
    protected $item;

    /**
     * Create a new rule instance.
     *
     * @param $item
     */
    public function __construct($item = null)
    {
        $this->item = $item;
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        return !is_a($this->item, Item::class) || !$this->item->trashed();
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return sprintf(
            'Eksemplaret er slettet. Du kan gjenopprette det på <a href="%s">eksemplarsiden</a> hvis du ønsker det.',
            action('ItemsController@show', $this->item->id)
        );
    }
}
