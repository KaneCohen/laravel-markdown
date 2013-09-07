# Markdown Extra for Laravel 4

## Installation

Add following require to your `composer.json` file:

~~~
    "cohensive/markdown": "dev-master"
~~~

Then run `composer install` or `composer update` to download it and autoload.

In `providers` array you need to add new package:

~~~
'providers' => array(

	//...
	'Cohensive\Markdown\MarkdownServiceProvider',
	//...

)
~~~

In aliases:

~~~
'aliases' => array(

	//...
	'Markdown' => 'Cohensive\Markdown\Facades\Markdown'
	//...

)
~~~


And after all that, we need to add config file, containing several options for Markdown.

~~~
	php artisan config:publish cohensive/markdown
~~~

## Usage

~~~
	Markdown::transform('Hello **World**');
~~~
