# Markdown Extra for Laravel 4

This is not a canonical Markdown version. This variant contains several differences:

1. One line break will result in a `<br />` tag - just like in Github Markdown.
2. Underscores (\_) in the middle of the word won't add italics or bold stylings.
3. Grave character (~ also known as tilde) will result in `<s>` tag usually used
to add line-through or strike-through styling.
4. Double grave character will result in `<del>` tag wrapping.

## Installation

Add following require to your `composer.json` file:

~~~
	// For Laravel 4.1.x+
	"cohensive/markdown": "dev-master"
	// OR for Laravel 4.0.x
	"cohensive/markdown": "4.0.0"
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

## Laravel 5

This package won't be supporting Laravel 5 version. Use CommonMark.
