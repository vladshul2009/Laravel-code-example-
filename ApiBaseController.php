<?php

namespace App\Http\Controllers;

use App\Advertisement;
use App\AdvertisementTracking;
use App\Article;
use App\BookmarkedArticle;
use App\RssCategory;
use App\RssFeed;
use App\FavoriteFeed;
use App\UserArticleViews;
use Carbon\Carbon;
use Illuminate\Routing\Controller as BaseController;
use Exception;
use Illuminate\Support\Facades\DB;
use PicoFeed\Reader\Reader;

class ApiBaseController extends BaseController
{
    protected $user = null;

    protected function sendResponse($data = [], $errors = [])
    {
        $status = empty($errors) ? 200 : 400;
        $errors = is_array($errors) ? $errors : (array)$errors;
        $response = ['data' => array_except($data, ['token']), 'errors' => $errors];
        if (isset($data['token'])) {
            $response['token'] = $data['token'];
        }
        return response()->json($response, $status);
    }

    protected function makeErrorsArray($errors)
    {
        $errorsArray = [];
        foreach ($errors as $error) {
            foreach ($error as $errorString) {
                $errorsArray[] = $errorString;
            }
        }
        return $errorsArray;
    }

    private function saveArticle($content, $category_id, $article_date)
    {
        Article::updateOrCreate(
            [
                'article_id' => $content['id']
            ],
            [
                'feed_id' => $content['feed_id'],
                'category_id' => $category_id,
                'content' => json_encode($content),
                'article_date' => $article_date
            ]);
    }

    private function loadArticles($feed_id, $limit)
    {
        $result = [];
        $query = Article::where('feed_id', $feed_id);
        $query->orderBy('article_date', 'desc');
        $articles = $query->get();
        if (!is_null($limit)) {
            $articles = $articles->take($limit);
        }
        if (count($articles)) {
            foreach ($articles as $article) {
                $result[] = json_decode($article->content, true);
            }
        }
        return $result;
    }

    private function isActualFeed($feed)
    {
        if ($feed->lastRead) {
            $today = Carbon::now();
            return ($today->diffInMinutes(Carbon::parse($feed->lastRead)) < 60) ? true : false;
        } else {
            return false;
        }
    }

    protected function getFeedContent($feedId, $limit = null)
    {
        $rssFeed = RssFeed::find($feedId);
        $reader = new Reader;
        try {
            if ($this->isActualFeed($rssFeed)) {
                $resource = $reader->download($rssFeed->url, $rssFeed->lastModified, $rssFeed->etag);
            } else {
                $resource = $reader->download($rssFeed->url);
            }
            if ($resource->isModified()) {
                $parser = $reader->getParser(
                    $resource->getUrl(),
                    $resource->getContent(),
                    $resource->getEncoding()
                );
                $feedContent = $parser->execute();
                $rssFeed->lastModified = $resource->getLastModified();
                $rssFeed->etag = $resource->getEtag();
                $rssFeed->lastRead = date('Y-m-d H:i:s');
                $rssFeed->save();
                $resultFeed = [];
                foreach ($feedContent->getItems() as $item) {
                    $content = [
                        'id' => $item->getId(),
                        'title' => $item->getTitle(),
                        'date' => $item->getPublishedDate()->format('M j, Y g:i a'),
                        'description' => trim(strip_tags($item->getTag('description')[0])),
                        'content' => $item->getContent(),
                        'url' => $item->getUrl(),
                        'feed_id' => $rssFeed->id,
                        'feed_title' => $rssFeed->name,
                        'category_title' => $rssFeed->category()->first()->title,
                        'image' => str_contains($item->getEnclosureType(), 'image') ? $item->getEnclosureUrl() : null
                    ];
                    if (is_null($content['image'])) {
                        if ($pos = strpos($content['content'], '<img') !== false) {
                            $doc = new \DOMDocument();
                            $doc->loadHTML($content['content']);
                            $xpath = new \DOMXPath($doc);
                            $content['image'] = $xpath->evaluate("string(//img/@src)");
                        }
                    }
                    $content['content'] = preg_replace('/(<img[^>]+>(?:<\/img>)?)/i', '$1<br /><br /><br />', $content['content']);
                    $this->saveArticle($content, $rssFeed->category()->first()->id, $item->getPublishedDate()->format('Y-m-d H:i:s'));
                    if (is_null($limit) or count($resultFeed) < $limit) {
                        $resultFeed[] = $content;
                    }
                }
            } else {
                $resultFeed = $this->loadArticles($rssFeed->id, $limit);
            }
        } catch (Exception $e) {
            return [];
        }
        $advertisement = $this->getAdvertisement2($rssFeed->category()->first()->title);
        $order = 0;
        foreach ($resultFeed as &$item) {
            if (is_null($this->user)) {
                $item['bookmarked'] = false;
                $item['followed'] = false;
                $item['viewed'] = false;
                $item['rating'] = null;
            } else {
                $item['bookmarked'] = (boolean)BookmarkedArticle::where('user_id', $this->user->id)->where('article_id', $item['id'])->count();
                $item['followed'] = (boolean)FavoriteFeed::where('user_id', $this->user->id)->where('feed_id', $item['feed_id'])->count();
                $item['viewed'] = (boolean)UserArticleViews::where('user_id', $this->user->id)->where('article_id', $item['id'])->count();
                $item['rating'] = null;
            }
            $item['advertisement'] = (!is_null($advertisement) and $order == $advertisement->order) ? $advertisement : null;
            $order++;
        }
        return $resultFeed;
    }

    protected function getAdvertisement($category_title)
    {
        return null;
    }

    protected function getAdvertisement2($category_title)
    {
        if (!is_null($this->user) and $this->user->paid) {
            return null;
        }
        $category = RssCategory::where('title', $category_title)->first();
        $advertisements = DB::table('advertisements')
            ->where('from_date', '<=', date('Y-m-d 00:00:00'))
            ->where(function ($query) {
                $query->where('to_date', '>=', date('Y-m-d 00:00:00'))
                    ->orWhereNull('to_date');
            })
            ->get()->toArray();
        if (!count($advertisements)) {
            return null;
        }
        $makePriority = function ($advertisement) {
            $result = [];
            for ($i = 0; $i < $advertisement->priority; $i++) {
                $result[] = $advertisement->id;
            }
            return $result;
        };
        $advertisement_ids = [];
        foreach ($advertisements as $key => $advertisement) {
            if ($advertisement->categories_ids == '0') {
                $advertisement_ids = array_merge($advertisement_ids, $makePriority($advertisement));
                continue;
            }
            $categories_ids = explode(',', $advertisement->categories_ids);
            if (!in_array($category->id, $categories_ids)) {
                unset($advertisements[$key]);
            } else {
                $advertisement_ids = array_merge($advertisement_ids, $makePriority($advertisement));
            }
        }
        shuffle($advertisement_ids);
        $advertisement = Advertisement::find($advertisement_ids[rand(0, count($advertisement_ids) - 1)]);
        DB::table('advertisements')->where('id', $advertisement->id)->increment('views');
        $advertisement->deleteProps(['name', 'categories_ids', 'from_date', 'to_date', 'priority', 'created_at', 'updated_at']);
        return $advertisement;
    }

}