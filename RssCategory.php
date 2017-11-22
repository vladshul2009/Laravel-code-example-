<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class RssCategory extends Model
{
    protected $casts = ['id' => 'string'];

    protected $hidden = ['created_at', 'updated_at'];

    public function getImageAttribute($value)
    {
        return asset($value);
    }


    public function favoriteFeeds()
    {
        return $this->hasMany(FavoriteFeed::class);
    }
}
