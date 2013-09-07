<?php
namespace Cohensive\Markdown;

use Illuminate\Foundation\Application;

class Factory
{

	/**
	 * Current configuration.
	 *
	 * @var Illuminate\Config\FileLoader
	 */
	protected $config;

	/**
	 * Markdown instance.
	 *
	 * \Cohensive\Markdown\Markdown
	 */
	protected $instance;

	/**
	 * Create Markdown Factory.
	 *
	 * @param  Illuminate\Foundation\Application  $app
	 * @return void
	 */
	public function __construct(Application $app)
	{
		$this->config = $app['config'];
	}

	/**
	 * Create Markdown instance.
	 *
	 * @return Cohensive\Markdown\Markdown
	 */
	public function make($extra = null)
	{

		if (is_null($extra)) {
			$extra = $this->config->get('markdown::extra');
		}

		if ($extra) {
			$markdown = new MarkdownExtra;
		} else {
			$markdown = new Markdown;
		}

		return $this->applyConfig($markdown);

	}

	/**
	 * Apply application configs to Markdown instance.
	 *
	 * @param Cohensive\Markdown\Markdown  $markdown
	 * @return Cohensive\Markdown\Markdown
	 */
	public function applyConfig($markdown)
	{
		foreach ($this->config->get('markdown::markdown') as $attribute => $val) {
			$markdown->{$attribute} = $val;
		}
		return $markdown;
	}

	/**
	 * Get default Markdow instance for basic usage with facade..
	 *
	 * @return Cohensive\Markdown\Markdown
	 */
	public function getInstance()
	{
		if (is_null($this->instance)) {
			return $this->instance = $this->make();
		} else {
			return $this->instance;
		}
	}

	/**
	 * Dynamically pass methods to the markdown.
	 *
	 * @param  string  $method
	 * @param  array   $parameters
	 * @return mixed
	 */
	public function __call($method, $parameters)
	{
		return call_user_func_array(array($this->getInstance(), $method), $parameters);
	}

}
