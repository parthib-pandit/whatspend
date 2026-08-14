@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'font-medium text-sm text-[#5B9279]']) }}>
        {{ $status }}
    </div>
@endif