<?php

namespace Tests\Traits;

use Illuminate\Support\Facades\Auth;

trait ResetsAuthGuards
{
    /**
     * Reset Laravel's auth guards before each request so a user resolved in a
     * previous request within the same test process is not reused.
     *
     * @param  array  $server  the server variables.
     * @return \Illuminate\Testing\TestResponse
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        Auth::forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}