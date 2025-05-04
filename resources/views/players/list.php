{% extends 'layouts/main' %}

{% section 'title' %}Basic Test{% endsection %}
{% section 'content' %}

<!-- Test 1: Basic variable output -->
<p>Variable: {{ title }}</p>

<!-- Test 2: If condition -->
{% if true %}
<p>Condition works</p>
{% endif %}

<!-- Test 3: Foreach loop -->
{% foreach [1, 2, 3] as item %}
<p>Item: {{ item }}</p>
{% endforeach %}

<!-- Test 4: Date formatting (possible issue area) -->
<p>Date: {{ dateFormat('2023-01-01', 'Y-m-d') }}</p>

<!-- Test 5: Pipe filter -->
<p>Players count: {{ players|length }}</p>

{% endsection %}