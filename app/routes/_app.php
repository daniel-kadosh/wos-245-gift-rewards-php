<?php

/*
app()->get('/', function () {
    #response()->json(['message' => 'Congrats!! You\'re on Leaf API']);
    #response()->status(400)->json(['message' => 'home']);
});
*/

app()->get("/", "WosController@index");
app()->get("/players", "WosController@players");
app()->get('/add/{player_id}', "WosController@add");
app()->get('/remove/{player_id}', "WosController@remove");
app()->get("/send/{gift_code}", "WosController@send");

// Debugging...
if (_env('APP_DEBUG')=='true') {
    app()->get("/pi", function() {
        phpinfo(INFO_ALL);
    });
}
