<?php

namespace App\Http\Controllers\Api;

use App\Article;
use App\BookmarkedArticle;
use App\FavoriteFeed;
use App\Http\Controllers\ApiBaseController;
use App\RssCategory;
use App\RssFeed;
use App\UserArticleViews;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Validator;

class ApiController extends ApiBaseController
{

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->user = Auth::user();
            return $next($request);
        });
    }

    public function addFavoriteFeed(Request $request)
    {
        $validator = Validator::make($request->input(), [
            'feedId' => 'required|numeric'
        ]);
        if ($validator->fails()) {
            return $this->sendResponse([], $this->makeErrorsArray($validator->errors()->getMessages()));
        }
        $feed = RssFeed::find($request->input('feedId'));
        if ($feed) {
            FavoriteFeed::updateOrCreate(
                [
                    'user_id' => $this->user->id,
                    'feed_id' => $request->input('feedId'),
                    'feed_title' => $feed->name,
                    'rss_category_id' => $feed->category()->first()->id
                ]);
        }
        $result = RssCategory::with(['favoriteFeeds' => function ($query) {
            $query->where('user_id', $this->user->id);
        }])->has('favoriteFeeds')->get();

        return $this->sendResponse($result);
    }

    public function deleteFavoriteFeeds(Request $request)
    {
        $validator = Validator::make($request->input(), [
            'feedIds' => 'required_without:categoryIds|array',
            'categoryIds' => 'required_without:feedIds|array',
        ]);
        if ($validator->fails()) {
            return $this->sendResponse([], $this->makeErrorsArray($validator->errors()->getMessages()));
        }
        $feedIds = $request->input('feedIds');
        $categoryIds = $request->input('categoryIds');
        if (count($categoryIds)) {
            FavoriteFeed::where('user_id', $this->user->id)->whereIn('rss_category_id', $categoryIds)->delete();
        }
        if (count($feedIds)) {
            foreach ($feedIds as $feed_id) {
                $favoriteFeed = FavoriteFeed::where('user_id', $this->user->id)->where('feed_id', $feed_id)->first();
                if ($favoriteFeed) {
                    $favoriteFeed->delete();
                }
            }
        }

        return $this->sendResponse($this->_getFavoriteFeeds());
    }

    private function _getFavoriteFeeds()
    {
        $result = RssCategory::with(['favoriteFeeds' => function ($query) {
            $query->where('user_id', $this->user->id);
        }])->has('favoriteFeeds')->get()->toArray();
        foreach ($result as $key => &$category) {
            $counter = 0;
            if (count($category['favorite_feeds'])) {
                foreach ($category['favorite_feeds'] as &$feed) {
                    $rssFeed = RssFeed::find($feed['feed_id']);
                    if ($rssFeed) {
                        $feed['image'] = $rssFeed['image'];
                        $feed['article_counter'] = (string)Article::where('feed_id', $feed['feed_id'])->count();
                        $counter += $feed['article_counter'];
                        $feed['id'] = $feed['feed_id'];
                        $feed['name'] = $feed['feed_title'];
                        unset($feed['feed_id']);
                        unset($feed['feed_title']);
                    }
                }
            } else {
                unset($result[$key]);
            }
            $category['articles_counter'] = (string)$counter;
        }
        return array_values($result);
    }

    public function getFavoriteFeeds()
    {
        return $this->sendResponse($this->_getFavoriteFeeds());
    }

    public function getTodayArticles()
    {
        $articles = [];
        $favoriteCategories = RssCategory::with(['favoriteFeeds' => function ($query) {
            $query->where('user_id', $this->user->id);
        }])->has('favoriteFeeds')->get()->toArray();
        if (!count($favoriteCategories)) {
            return $this->sendResponse([], 'no favorite feeds found');
        }
        foreach ($favoriteCategories as $category) {
            $favoriteFeeds = $category['favorite_feeds'];
            if (count($favoriteFeeds)) {
                foreach ($favoriteFeeds as $feed) {
                    $articles = array_merge($articles, $this->getFeedContent($feed['feed_id'], 7));
                }
            }
        }
        return $this->sendResponse($articles);
    }

    public function setBookmarkedArticle(Request $request)
    {
        $validator = Validator::make($request->input(), [
            'feedId' => 'required|numeric',
            'articleId' => 'required|string',
            'title' => 'required|string',
            'url' => 'required|string',
            'description' => 'required|string',
            'content' => 'required|string',
            'date' => 'required|string',
            'categoryTitle' => 'required|string',
            'feedTitle' => 'required|string',
            'image' => 'string'
        ]);
        if ($validator->fails()) {
            return $this->sendResponse([], $this->makeErrorsArray($validator->errors()->getMessages()));
        }
        if (BookmarkedArticle::where('user_id', $this->user->id)->where('article_id', $request->input('articleId'))->count()) {
            return $this->sendResponse([], ['Already bookmarked']);
        }
        $bookmarkedArticle = new BookmarkedArticle();
        $bookmarkedArticle->user_id = $this->user->id;
        $bookmarkedArticle->feed_id = $request->input('feedId');
        $bookmarkedArticle->article_id = $request->input('articleId');
        $bookmarkedArticle->title = $request->input('title');
        $bookmarkedArticle->url = $request->input('url');
        $bookmarkedArticle->description = $request->input('description');
        $bookmarkedArticle->content = $request->input('content');
        $bookmarkedArticle->category_title = $request->input('categoryTitle');
        $bookmarkedArticle->feed_title = $request->input('feedTitle');
        $bookmarkedArticle->date = date('Y-m-d H:i:s', strtotime($request->input('date')));
        $bookmarkedArticle->image = $request->input('image');
        $bookmarkedArticle->feed_title = $request->input('feedTitle');
        $bookmarkedArticle->save();
        return $this->sendResponse();
    }

    public function getBookmarkedArticles()
    {
        $resultFeed = $this->user->bookmarkedArticles()->orderBy('created_at', 'desc')->get()->toArray();
        foreach ($resultFeed as &$item) {
            $item['bookmarked'] = (boolean)BookmarkedArticle::where('user_id', $this->user->id)->where('article_id', $item['article_id'])->count();
            $item['followed'] = (boolean)FavoriteFeed::where('user_id', $this->user->id)->where('feed_id', $item['feed_id'])->count();
            $item['viewed'] = (boolean)UserArticleViews::where('user_id', $this->user->id)->where('article_id', $item['article_id'])->count();
            $item['rating'] = null;
            $item['advertisement'] = $this->getAdvertisement($item['category_title']);
            $item['id'] = $item['article_id'];
            if (!isset($item['image']) or is_null($item['image'])) {
                if ($pos = strpos($item['content'], '<img') !== false) {
                    $doc = new \DOMDocument();
                    $doc->loadHTML($item['content']);
                    $xpath = new \DOMXPath($doc);
                    $item['image'] = $xpath->evaluate("string(//img/@src)");
                }
            }
            $item['content'] = preg_replace('/(<img[^>]+>(?:<\/img>)?)/i', '$1<br /><br /><br />', $item['content']);
            unset($item['user_id']);
            unset($item['article_id']);
        }
        return $this->sendResponse($resultFeed);
    }

    public function deleteBookmarkedArticle(Request $request)
    {
        $validator = Validator::make($request->input(), [
            'articleId' => 'required|string'
        ]);
        if ($validator->fails()) {
            return $this->sendResponse([], $this->makeErrorsArray($validator->errors()->getMessages()));
        }
        $bookmarkedArticle = BookmarkedArticle::where('user_id', $this->user->id)
            ->where('article_id', $request->input('articleId'))
            ->first();
        if ($bookmarkedArticle) {
            $bookmarkedArticle->delete();
        }
        return $this->sendResponse();
    }

    public function markArticlesViewed(Request $request)
    {
        $validator = Validator::make($request->input(), [
            'articleIds' => 'required|array'
        ]);
        if ($validator->fails()) {
            return $this->sendResponse([], $this->makeErrorsArray($validator->errors()->getMessages()));
        }
        foreach ($request->input('articleIds') as $article_id) {
            $userArticleView = new UserArticleViews();
            $userArticleView->user_id = $this->user->id;
            $userArticleView->article_id = $article_id;
            $userArticleView->save();
        }
        return $this->sendResponse();
    }

    public function getPopularArticles(Request $request)
    {
        $validator = Validator::make($request->input(), [
            'offset' => 'numeric|min:0',
            'limit' => 'numeric|min:10|max:100',
            'period' => 'in:day,3days,week,month,quarter,year,alltime'
        ]);
        if ($validator->fails()) {
            return $this->sendResponse([], $this->makeErrorsArray($validator->errors()->getMessages()));
        }
        $offset = $request->input('offset', 0);
        $limit = $request->input('limit', 30);
        $period = $request->input('limit', 'day');

        $columns = [
            'article_tracking.article_id',
            DB::raw('COUNT(article_views.id) AS clicks'),
        ];
        $query = DB::table('article_tracking')
            ->select($columns)
            ->join('article_views', 'article_tracking.id', '=', 'article_views.tracking_id')
            ->groupBy('article_views.tracking_id', 'article_tracking.article_id')
            ->orderBy('clicks', 'desc')
            ->offset($offset)
            ->limit($limit);
        $times = [
            '3days' => '-3 days',
            'week' => '-1 week',
            'month' => '-1 month',
            'quarter' => '-3 month',
            'year' => '-1 year'
        ];
        if ($period == 'day') {
            $query->whereBetween('article_views.created_at', [date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59')]);
        } else {
            $query->whereBetween('article_views.created_at', [date('Y-m-d 00:00:00', strtotime($times[$period])), date('Y-m-d 23:59:59')]);
        }
        $articles = $query->get();
        $result = [];
        foreach ($articles as $article) {
            $articleContent = Article::where('article_id', $article->article_id)->first();
            $item = json_decode($articleContent->content, true);
            $item['bookmarked'] = (boolean)BookmarkedArticle::where('user_id', $this->user->id)->where('article_id', $item['id'])->count();
            $item['followed'] = (boolean)FavoriteFeed::where('user_id', $this->user->id)->where('feed_id', $item['feed_id'])->count();
            $item['viewed'] = (boolean)UserArticleViews::where('user_id', $this->user->id)->where('article_id', $item['id'])->count();
            $item['advertisement'] = $this->getAdvertisement($item['category_title']);
            $item['rating'] = (string)$article->clicks;
            $result[] = $item;
        }
        return $this->sendResponse($result);

    }

    public function paidSubscription()
    {
        return $this->sendResponse();
    }
}
