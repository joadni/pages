
@php
$hasSlot = !empty($slot->toHtml()) || $is;
$bgCss = $hasSlot ? 'bg_' . uniqid() : $attributes->get('class');
$class = $attributes->get('class') . ' ' . ($hasSlot ? ($bgCss) : null);
$is = $is ?? 'div';
$stack = $attributes->get('stack') ? $attributes->get('stack') : null;
@endphp

@if($bgCss)

@if($stack)
  @push($stack)
@endif

<style>
  .{{ $bgCss }} {
    background-image: url({!! $defaultSrc !!});
  }

  @foreach($defaultPaths as $resolution => $src)
    @if($resolution !== '1x')
      @media
      (-webkit-min-device-pixel-ratio: {{ intval($resolution) }}),
      (min--moz-device-pixel-ratio: {{ intval($resolution) }}),
      (-o-min-device-pixel-ratio: {{ intval($resolution) }}/1),
      (min-device-pixel-ratio: {{ intval($resolution) }}),
      (min-resolution: {{ intval($resolution) }}dppx) {
        .{{ $bgCss }} {
          background-image: url({!! $src !!});
        }
      }
    @endif
  @endforeach

  @foreach ($srcSets as $srcSet)
    @isset($srcSet['sources']['1x'])
      @media (max-width: {{ $srcSet['maxWidth'] }}px) {
        .{{ $bgCss }} {
          background-image: url({!! $srcSet['sources']['1x'] !!});
        }
      }
    @endisset
  @endforeach

  @foreach ($srcSets as $srcSet)
    @foreach($srcSet['sources'] as $resolution => $src)
      @if($resolution !== '1x')
        @media
        (-webkit-min-device-pixel-ratio: {{ intval($resolution) }}) and (max-width: {{ $srcSet['maxWidth'] }}px),
        (min--moz-device-pixel-ratio: {{ intval($resolution) }}) and (max-width: {{ $srcSet['maxWidth'] }}px),
        (-o-min-device-pixel-ratio: {{ intval($resolution) }}/1) and (max-width: {{ $srcSet['maxWidth'] }}px),
        (min-device-pixel-ratio: {{ intval($resolution) }}) and (max-width: {{ $srcSet['maxWidth'] }}px),
        (min-resolution: {{ intval($resolution) }}dppx) and (max-width: {{ $srcSet['maxWidth'] }}px)
        {
          .{{ $bgCss }} {
            background-image: url({!! $src !!});
          }
        }
      @endif
    @endforeach
  @endforeach
</style>

@if($stack)
  @endpush
@endif

@if($hasSlot)
  <{{ $is }} class="{!! $class !!}" {!! $attributes->except(['stack', 'class'])->toHtml() !!}>
    {{ $slot }}
  </{{ $is }}>
@endif

@endif
