<?php

namespace App;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use function Stringy\create as s;

class Item extends Model
{

    use SoftDeletes;

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = ['deleted_at'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['barcode', 'library_id', 'note', 'thing_id'];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = ['last_loan', 'available'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'properties' => 'array',
    ];

    /**
     * Prepare a date for array / JSON serialization.
     *
     * @param  \DateTimeInterface  $date
     * @return string
     */
    protected function serializeDate(DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function thing()
    {
        return $this->belongsTo(Thing::class, 'thing_id');
    }

    public function library()
    {
        return $this->belongsTo(Library::class, 'library_id');
    }

    /**
     * Get the loans for this items. By default, only active loans are returned,
     * so the result should be zero or one loan. But all active and former loans can
     * be returned by adding `withTrashed()`.
     */
    public function loans()
    {
        return $this->hasMany(Loan::class)
            ->with('user')
            ->latest();
    }

    /**
     * Get the last loan, active or not.
     *
     * @return bool
     */
    public function getLastLoanAttribute()
    {
        return $this->attributes['last_loan'] = $this->loans()
                ->withTrashed()
                ->orderBy('created_at', 'desc')
                ->first();
    }

    /**
     * Whether item is available or not.
     *
     * @return bool
     */
    public function getAvailableAttribute()
    {
        return !count($this->loans);
    }

    public function allLoans()
    {
        $library_id = auth()->user()->id;

        return $this->hasMany(Loan::class)
            ->with('user')
            ->withTrashed()
            ->where('library_id', $library_id)
            ->orderBy('created_at', 'desc');
    }

    public function lost()
    {
        $this->is_lost = true;
        $this->save();
        $this->delete();
    }

    public function found()
    {
        $this->restore();

        if ($this->is_lost) {
            \Log::info(sprintf(
                'Registrerte %s som funnet.',
                $this->formattedLink(false, false)
            ), ['library' => \Auth::user()->name]);
            $this->is_lost = false;
            $this->save();
        }
    }

    public function formattedLink($ucfirst = false, $definite = true)
    {
        $name = s($this->thing->properties->get($definite ? 'name_definite.nob' : 'name_indefinite.nob'));
        $name = $ucfirst ? $name->upperCaseFirst() : $name->lowerCaseFirst();

        return sprintf(
            '<a href="%s">%s</a>',
            action('ItemsController@show', $this->id),
            $name
        );
    }
}
