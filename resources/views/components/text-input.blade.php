@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'bg-[#15120E] border-[#332C1F] text-[#EDE6D6] placeholder-[#6b6355] focus:border-[#C9A227] focus:ring-[#C9A227] rounded-md shadow-sm']) }}>