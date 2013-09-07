# Markdown Extra for Laravel 4

This is not a stock Markdown version. It contains various fixes, additions and
has a different behaviour of line breaks (one line break will result in `<br/>` tag).

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
