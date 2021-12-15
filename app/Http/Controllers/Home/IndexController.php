<?php

namespace App\Http\Controllers\Home;

use App\Http\Requests\Comment\Store;
use App\Models\Category;
use App\Models\Article;
use App\Models\ArticleTag;
use App\Models\Chat;
use App\Models\Comment;
use App\Models\Zan;
use App\Models\Config;
use App\Models\OauthUser;
use App\Models\Tag;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Cache;

class IndexController extends Controller
{
    /**
     * 首页
     *
     * @param Article $articleModel
     * @return mixed
     */
    public function index(Article $articleModel)
	{
	    // 获取文章列表数据
        $article = Article::where('is_show', '>', 0)->select('id', 'category_id', 'title', 'author', 'description', 'cover', 'created_at','click','zan_num')
            ->orderBy('created_at', 'desc')
            ->with(['category', 'tags'])->withCount(['comments'])
            ->paginate(10);
        $config = cache('config');
        $head = [
            'title' => $config->get('WEB_TITLE'),
            'keywords' => $config->get('WEB_KEYWORDS'),
            'description' => $config->get('WEB_DESCRIPTION'),
        ];

        $assign = [
            'category_id' => 'index',
            'article' => $article,
            'head' => $head,
            'tagName' => ''
        ];
		return view('home.index.index', $assign);
	}

    /**
     * 文章详情
     *
     * @param         $id
     * @param Article $articleModel
     * @param Comment $commentModel
     *
     * @return $this
     */
    public function article($id, Request $request, Article $articleModel, Comment $commentModel)
    {
        // 获取文章数据
        $data = Article::with(['category', 'tags'])->find($id);

        if (is_null($data)) {
            return abort(404);
        }
        // 同一个用户访问同一篇文章每天只增加1个访问量  使用 ip+id 作为 key 判别
        $ipAndId = 'articleRequestList'.$request->ip().':'.$id;
        if (!Cache::has($ipAndId)) {
            cache([$ipAndId => ''], 1440);
            // 文章点击量+1
            $data->increment('click');
        }

        if($data['category_id'] == 5){
            // 获取上一篇
            $prev = $articleModel
                ->select('id', 'title')
                ->orderBy('id', 'desc')
                ->where('category_id',5)
                ->where('id', '<', $id)
                ->limit(1)
                ->first();

            // 获取下一篇
            $next = $articleModel
                ->select('id', 'title')
                ->orderBy('id', 'asc')
                ->where('category_id',5)
                ->where('id', '>', $id)
                ->limit(1)
                ->first();
        }else{
            // 获取上一篇
            $prev = $articleModel
                ->select('id', 'title')
                ->orderBy('id', 'desc')
                ->where('id', '<', $id)
                ->limit(1)
                ->first();

            // 获取下一篇
            $next = $articleModel
                ->select('id', 'title')
                ->orderBy('id', 'asc')
                ->where('id', '>', $id)
                ->limit(1)
                ->first();
        }

        // 获取评论
        $comment = $commentModel->getDataByArticleId($id);
        $category_id = $data->category->id;
        $assign = compact('category_id', 'data', 'prev', 'next', 'comment');
        return view('home.index.article', $assign);
    }

    /**
     * 文章评论
     *
     * @param Comment $commentModel
     */
    public function comment(Store $request, Comment $commentModel, OauthUser $oauthUserModel)
    {
        $data = $request->only('content', 'article_id', 'pid');
        // 获取用户id
        $userId = session('user.id');
        // 如果用户输入邮箱；则将邮箱记录入oauth_user表中
        $email = $request->input('email');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            // 修改邮箱
            $oauthUserMap = [
                'id' => $userId
            ];
            $oauthUserData = [
                'email' => $email
            ];
            $oauthUserModel->updateData($oauthUserMap, $oauthUserData);
            session(['user.email' => $email]);
        }
        // 存储评论
        $id = $commentModel->storeData($data);
        // 更新缓存
        Cache::forget('common:newComment');
        Cache::forget('common:commentCount');
        return ajax_return(200, ['id' => $id]);
    }

    /**
     * 文章点赞
     *
     * @param Zan $zanModel
     */
    public function zan(Request $request, Article $articleModel)
    {
        $aid = $request->input('aid');
        $res = $articleModel->where('id',$aid)->increment('zan_num');
        if($res){
            $num = Article::where('id',$aid)->select(['zan_num'])->first()->toArray();
            return ajax_return(200, ['num' => $num]);
        }
    }

    /**
     * 获取栏目下的文章
     *
     * @param Article $articleModel
     * @param $id
     * @return mixed
     */
    public function category(Article $articleModel, $id)
    {
        // 获取分类数据
        $category = Category::select('id', 'name', 'keywords', 'description')
            ->where('id', $id)
            ->first();
        if (is_null($category)) {
            return abort(404);
        }
        // 获取分类下的文章
        $article = $category->articles()
            ->orderBy('created_at', 'desc')
            ->with('tags')
            ->paginate(10);
        // 为了和首页共用 html ； 此处手动组合分类数据
        if ($article->isNotEmpty()) {
            $article->setCollection(
                collect(
                    $article->items()
                )->map(function ($v) use ($category) {
                    $v->category = $category;
                    return $v;
                })
            );
        }

        $head = [
            'title' => $category->name,
            'keywords' => $category->keywords,
            'description' => $category->description,
        ];
        $assign = [
            'category_id' => $id,
            'article' => $article,
            'tagName' => '',
            'title' => $category->name,
            'head' => $head
        ];
        return view('home.index.index', $assign);
    }

    /**
     * 获取标签下的文章
     * @param $id
     * @param Article $articleModel
     * @return mixed
     */
    public function tag($id, Article $articleModel)
    {
        // 获取标签
        $tag = Tag::select('id', 'name')->where('id', $id)->first();
        if (is_null($tag)) {
            return abort(404);
        }
        // TODO 不取 markdown 和 html 字段
        // 获取标签下的文章
        $article = $tag->articles()
            ->where('is_show', 1)
            ->orderBy('created_at', 'desc')
            ->with(['category', 'tags'])
            ->paginate(10);
        $head = [
            'title' => $tag->name,
            'keywords' => '',
            'description' => '',
        ];
        $assign = [
            'category_id' => 'index',
            'article' => $article,
            'tagName' => $tag->name,
            'title' => $tag->name,
            'head' => $head
        ];
        return view('home.index.index', $assign);
    }

    /**
     * 随言碎语
     * @return mixed
     */
    public function chat()
    {
        $chat = Chat::orderBy('created_at', 'desc')->get();
        $assign =[
            'category_id' => 'chat',
            'chat' => $chat,
            'title' => '随言碎语',
        ];
        return view('home.index.chat', $assign);
    }

    /**
     * 开源项目
     * @return mixed
     */
    public function git()
    {
        $assign = [
            'category_id' => 'git',
            'title' => '开源项目',
        ];
        return view('home.index.git', $assign);
    }

    /**
     * 留言板
     * @return mixed
     */
    public function contact()
    {
        //$chat = Chat::orderBy('created_at', 'desc')->get();
        $assign =[
            'category_id' => 'contact',
            //'chat' => $chat,
            'title' => '留言板',
        ];
        return view('home.index.contact', $assign);
    }

    /**
     * 在线工具
     * @return mixed
     */
    public function tool()
    {
        //$chat = Chat::orderBy('created_at', 'desc')->get();
        $assign =[
            'category_id' => 'tool',
            //'chat' => $chat,
            'title' => '在线工具',
        ];
        return view('home.index.tool', $assign);
    }

    /**
     * puamap
     * @return mixed
     */
    public function puamap()
    {
        $puamap = Article::select('id', 'category_id', 'title', 'author', 'description', 'cover', 'created_at','click','zan_num')
            ->orderBy('created_at', 'desc')
            ->with(['category', 'tags'])->withCount(['comments'])
            ->where('category_id',5)
            ->paginate(10);
        $assign =[
            'puamap' => $puamap,
            'category_id' => 'puamap',
            //'chat' => $chat,
            'title' => '把妹社区',
        ];

        return view('home.index.puamap', $assign);
    }

    //投稿
    public function tougao(){
        $assign =[
            'category_id' => 'add',
            //'chat' => $chat,
            'title' => '投稿 ',
        ];
        return view('home.index.add',$assign);
    }

    /**
     * 检测是否登录
     */
    public function checkLogin()
    {
        if (empty(session('user.id'))) {
            return 0;
        } else {
            return 1;
        }
    }

    /**
     * 搜索文章
     *
     * @param Request $request
     * @param Article $articleModel
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function search(Request $request)
    {
        // Purifier扩展包集成了HTMLPurifier防止XSS跨站攻击,其辅助函数clean
        $wd = clean($request->input('kw'));

        // 获取原生搜索结果而不是转化后的Eloquent模型，用raw()
        //$raw = Article::search($wd)->raw();
        $id = Article::search($wd)->keys();

        $articles = [];
        // 获取文章列表数据
        if ($id) {
            $articles = Article::select('id', 'category_id', 'title', 'author', 'description', 'cover', 'created_at', 'click', 'zan_num')
                ->whereIn('id', $id)
                ->orderBy('created_at', 'desc')
                ->with(['category', 'tags'])->withCount(['comments'])
                ->paginate(10);
        }

        $assign = [
            'category_id' => 'index',
            'article' => $articles,
            'tagName' => '',
            'title' => $wd,
            'head' => [
                'title' => $wd,
                'keywords' => '',
                'description' => '',
            ]
        ];

        return view('home.index.index', $assign);
    }

    /**
     * 用于做测试的方法
     */
    public function test()
    {

    }


}
