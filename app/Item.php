<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Item extends Model {

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
    protected $fillable = ['dokid', 'library_id'];

    public function thing()
    {
        return $this->belongsTo(Thing::class, 'thing_id');
    }

    public function library()
    {
        return $this->belongsTo(Library::class, 'library_id');
    }

	public function loans()
	{
		return $this->hasMany(Loan::class)
			->with('user');
	}

	public function allLoans()
	{
		$library_id = \Auth::user()->id;

		return $this->hasMany(Loan::class)
			->with('user')
			->withTrashed()
			->where('library_id', $library_id)
			->orderBy('created_at', 'desc');
	}
}
