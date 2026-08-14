@props(['active'])

@php
$classes = ($active ?? false)
            ? 'block w-full ps-3 pe-4 py-2 border-l-4 border-[#C9A227] text-start text-base font-medium text-[#EDE6D6] bg-[#1D1911] focus:outline-none focus:text-[#EDE6D6] focus:bg-[#1D1911] focus:border-[#C9A227] transition duration-150 ease-in-out'
            : 'block w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-[#B9AF98] hover:text-[#EDE6D6] hover:bg-[#1D1911] hover:border-[#332C1F] focus:outline-none focus:text-[#EDE6D6] focus:bg-[#1D1911] focus:border-[#332C1F] transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>