<?php

namespace App;

use Illuminate\Database\Eloquent\Model;


class RssFeed extends Model
{
    protected $hidden = ['created_at', 'updated_at'];

    protected $casts = ['id' => 'string', 'category_id' => 'string', 'counter' => 'string'];

    public function getImageAttribute($value)
    {
        return asset($value);
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'rss_tag', 'rss_id');
    }

    public function category()
    {
        return $this->belongsTo('App\RssCategory', 'category_id');
    }
    
}
