<?php

/*
app()->get('/', function () {
    #response()->json(['message' => 'Congrats!! You\'re on Leaf API']);
    #response()->status(400)->json(['message' => 'home']);
});
*/

app()->get("/", "WosController@index");
app()->get("/players", "WosController@players");
app()->get('/add/{player_id}', "$controller@add");
app()->get('/remove/{player_id}', "$controller@remove");
app()->get("/send", "WosController@send");

/*
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
