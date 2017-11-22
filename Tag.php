<?php

namespace App;

use Illuminate\Database\Eloquent\Model;


class Tag extends Model
{
    protected $hidden = ['created_at', 'updated_at', 'pivot', 'id'];

    protected $casts = ['id' => 'string'];

    public function rssFeeds()
    {
        return $this->belongsToMany(RssFeed::class, 'rss_tag', 'tag_id', 'rss_id');
    }

}
