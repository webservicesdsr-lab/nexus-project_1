<?php
http_response_code(404);
get_header(); ?>

<div class="nexus-wrap">
  <div class="nexus-card">

    <img src="<?php echo esc_url( get_template_directory_uri() . '/assets/img/nexus-404.svg' ); ?>" alt="Page not available">

    <h1>Page not available</h1>

    <p>The resource you’re looking for doesn’t exist or is no longer accessible.</p>

    <ul>
      <li>The address is incorrect</li>
      <li>The page has been moved</li>
      <li>Access is restricted</li>
    </ul>

    <div class="actions">
      <a href="javascript:history.back()" class="back">Go back</a>
      <a href="<?php echo esc_url( home_url('/') ); ?>" class="home">Home</a>
    </div>

  </div>
</div>

<?php get_footer(); ?>
