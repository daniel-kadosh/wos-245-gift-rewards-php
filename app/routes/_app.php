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

app()->get("/download/{fileFormat}", "WosController@download");
app()->get("/download", "WosController@download");

app()->get("/admin", "WosController@admin");
app()->post("/admin/add", "WosController@adminAdd");        // POST: username, pswd
app()->post("/admin/remove", "WosController@adminRemove");  // POST: username
app()->post("/changepass", "WosController@adminChangePassword");  // POST: pswd

// Debugging...
if (_env('APP_DEBUG')=='true') {
    app()->get("/pi", function() {
        phpinfo(INFO_ALL);
    });
}
