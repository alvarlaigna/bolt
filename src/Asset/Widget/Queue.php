<?php
namespace Bolt\Asset\Widget;

use Bolt\Asset\AssetSortTrait;
use Bolt\Asset\Injector;
use Bolt\Asset\QueueInterface;
use Bolt\Asset\Snippet\Snippet;
use Bolt\Asset\Target;
use Bolt\Render;
use Doctrine\Common\Cache\CacheProvider;

/**
 * Widget queue processor.
 *
 * @author Gawain Lynch <gawain.lynch@gmail.com>
 * @author Bob den Otter <bob@twokings.nl>
 */
class Queue implements QueueInterface
{
    use AssetSortTrait;

    /** @var Widget[] Queue with snippets of HTML to insert. */
    protected $queue = [];
    /** @var \Bolt\Asset\Injector */
    protected $injector;
    /** @var \Doctrine\Common\Cache\CacheProvider */
    protected $cache;
    /** @var \Bolt\Render */
    protected $render;

    /** @var boolean */
    private $deferAdded;

    /**
     * Constructor.
     *
     * @param Injector      $injector
     * @param CacheProvider $cache
     * @param Render        $render
     */
    public function __construct(Injector $injector, CacheProvider $cache, Render $render)
    {
        $this->injector = $injector;
        $this->cache = $cache;
        $this->render = $render;
    }

    /**
     * Add a wiget to the queue.
     *
     * @param Widget $widget
     */
    public function add(Widget $widget)
    {
        $widget->setKey();
        $this->queue[$widget->getKey()] = $widget;
    }

    /**
     * Get a widget from the queue.
     *
     * @param string $key
     *
     * @return Widget
     */
    public function get($key)
    {
        return $this->queue[$key];
    }

    /**
     * Get a rendered (and potentially cached) widget from the queue.
     *
     * @param string $key
     *
     * @return \Twig_Markup|string
     */
    public function getRendered($key)
    {
        return $this->getHtml($this->queue[$key]);
    }

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        $this->queue = [];
    }

    /**
     * {@inheritdoc}
     */
    public function process($html)
    {
        // Process the widgets in the queue.
        foreach ($this->sort($this->queue) as $widget) {
            if ($widget->getType() === 'frontend' && $widget->isDeferred()) {
                $html = $this->addDeferredJavaScript($widget, $html);
            }
        }

        return $html;
    }

    /**
     * Get the queued widgets.
     *
     * @return \Bolt\Asset\Widget\Widget[]
     */
    public function getQueue()
    {
        return $this->queue;
    }

    /**
     * Render a location's widget.
     *
     * @param string $type
     * @param string $location
     *
     * @return string|null
     */
    public function render($type, $location)
    {
        $html = null;
        foreach ($this->queue as $widget) {
            if ($widget->getType() === $type && $widget->getLocation() === $location) {
                $html .= $this->addWidgetHolder($widget);
            }
        }

        return $html;
    }

    /**
     * Add a widget holder, empty if deferred.
     *
     * @param Widget $widget
     *
     * @return \Twig_Markup
     */
    protected function addWidgetHolder(Widget $widget)
    {
        return $this->render->render('widgetholder.twig', [
            'widget' => $widget,
            'html'   => $widget->isDeferred() ? '' : $this->getHtml($widget)
        ]);
    }

    /**
     * Get the HTML content from the widget.
     *
     * @param Widget $widget
     *
     * @throws \Exception
     *
     * @return string
     */
    protected function getHtml(Widget $widget)
    {
        $key = 'widget_' . $widget->getKey();
        if ($html = $this->cache->fetch($key)) {
            return $html;
        }

        $e = null;
        set_error_handler(function ($errno, $errstr) use (&$e) {
            return $e = new \Exception($errstr, $errno);
        });

        // Get the HTML from object cast and rethrow an exception if present
        $html = (string) $widget;

        restore_error_handler();

        if ($e) {
            throw $e;
        }
        if ($widget->getCacheDuration() !== null) {
            $this->cache->save($key, $html, $widget->getCacheDuration());
        }

        return $html;
    }

    /**
     * Insert a snippet of Javascript to fetch the actual widget's contents.
     *
     * @param Widget $widget
     * @param string $html
     *
     * @return string
     */
    protected function addDeferredJavaScript(Widget $widget, $html)
    {
        if ($this->deferAdded) {
            return $html;
        }

        $javaScript = $this->render->render('widgetjavascript.twig', [
            'widget' => $widget
        ]);
        $snippet = new Snippet(Target::AFTER_BODY_JS, (string) $javaScript);
        $this->deferAdded = true;

        return $this->injector->inject($snippet, Target::AFTER_BODY_JS, $html);
    }
}
