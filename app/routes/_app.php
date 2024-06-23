<?php

app()->get('/', function () {
    #response()->json(['message' => 'Congrats!! You\'re on Leaf API']);
    #response()->status(400)->json(['message' => 'home']);
    response()->markup("<h1>WOS #245 Gift Rewards</h1>
        <ul><li>Database players: <a href=\"/players\">/players</a></li>
        <li>Send a reward: <a href=\"/send/\">/send/</a>[giftcode]</li>
        <li>Add a player: <a href=\"/add/\">/add</a>[playerID]</li>
        <li>Remove a player: <a href=\"/remove/\">/remove</a>[playerID]</li>
        </ul>");
});

/*
app()->get("/wos", "WosController@index");
app()->match('GET|HEAD', '/posts', "$controller@index");
app()->post('/posts', "$controller@store");
app()->match('GET|HEAD', '/posts/create', "$controller@create");
app()->match('POST|DELETE', '/posts/{id}/delete', "$controller@destroy");
app()->match('POST|PUT|PATCH', '/posts/{id}/edit', "$controller@update");
app()->match('GET|HEAD', '/posts/{id}/edit', "$controller@edit");
app()->match('GET|HEAD', '/posts/{id}', "$controller@show");

app()->get('/users/{id}', function ($id) {
    response()->markup("This is user $id");

    # database
    #db()->connect('',_env('DATABASE_URL'),'','','sqlite');
    db()->autoConnect();

    $result = db()->query('SELECT * FROM users WHERE id = ?')->bind('1')->fetchObj();
    db()
        ->insert("users")
        ->params(["username" => "mychi"])
        ->execute();
    db()->select('users', 'name, created_at')->all();
    db()
        ->select('users')
        ->where(['name' => 'John Doe'])
        ->fetchObj();
});

app()->get('/users/(\d+)', function ($id) {
    response()->markup("The number passed in is: $id");
});

app()->get('/exit', function () use ($app) {
    // page.html is a file with content to render
    $app->response()->page('./page.html');
    // render + exit now
    response()->exit('Folder not found');
    // ?? retrieves ?name=xxx param from URL
    $data = request()->get('name');
});
*/