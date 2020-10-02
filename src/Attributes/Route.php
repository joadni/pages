<?php

namespace Netflex\Pages\Attributes;

#[Attribute]
class Route
{
  public $methods;
  public string $url;
  public string $action;

  public function __construct($methods, $url, $action)
  {
    $this->methods = $methods;
    $this->path = $url;
    $this->action = $action;
  }
}
