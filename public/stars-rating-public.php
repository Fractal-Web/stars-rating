<?php


if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'Stars_Rating_Public' ) ) :
	/**
	 * Class Stars_Rating
	 *
	 * Plugin's main class.
	 *
	 * @since 1.0.0
	 */
	final class Stars_Rating_Public {

		/**
		 * Single instance of Class.
		 *
		 * @since 1.0.0
		 * @var Stars_Rating
		 */
		protected static $_instance;

		/**
		 * Provides singleton instance.
		 *
		 * @since 1.0.0
		 * @return self instance
		 */
		public static function instance() {
			if ( is_null( self::$_instance ) ) {
				self::$_instance = new self();
			}

			return self::$_instance;
		}

		/**
		 * Status of the stars rating for the current post type
		 *
		 * @since 1.0.0
		 * @return bool
		 */
		public static function status() {

			$enabled_posts = get_option( 'enabled_post_types', array( 'post', 'page' ) );
			$post_status   = get_post_meta( get_the_ID(), 'sr-comments-rating', true );

			if ( ! is_array( $enabled_posts ) ) {
				$enabled_posts = (array)$enabled_posts;
			}

			$status = ( in_array( get_post_type(), $enabled_posts ) && ( '0' !== $post_status ) ) ? true : false;

			return $status;
		}

		/**
		 * Stars_Rating constructor.
		 * @since 1.0.0
		 */
		public function __construct() {

			$this->init_hooks();

			add_shortcode( 'stars_rating_avg', array( $this, 'rating_average_shortcode' ) );

			// Stars Rating plugin loaded action hook
			do_action( 'star_ratings_loaded' );

		}

		/**
		 * Initialize hooks.
		 *
		 * @since 1.0.0
		 */
		public function init_hooks() {

			add_action( 'comment_form_logged_in_before', array( $this, 'comment_form_fields' ) );
			add_action( 'comment_form_top', array( $this, 'comment_form_fields' ) );
			add_filter( 'preprocess_comment', array( $this, 'verify_comment_rating' ) );
			add_action( 'comment_post', array( $this, 'save_comment_rating' ) );
			add_filter( 'comment_text', array( $this, 'modify_comment' ) );

			$avg_rating_display  = get_option( ' avg_rating_display', 'show' );
			$google_search_stars = get_option( ' google_search_stars', 'show' );

			if ( 'show' === $avg_rating_display ) { // Check if average rating and comments are enabled for the post/page
				add_filter( "comments_template", array( $this, 'rating_average_markup' ) );
			}
			if ( 'show' === $google_search_stars ) {
				add_action( 'wp_head', array( $this, 'add_reviews_schema' ) );
			}
		}

		public function add_reviews_schema() {

			if ( ! self::status() ) {
				return;
			}

			$schema_name  = ucfirst( get_post_type() );
			$schema_title = get_the_title();
			$rating_stat  = $this->rating_stat();
			$review_type  = get_option( 'google_search_stars_type' );

			if ( ! empty( $review_type ) ) {
				$schema_name = $review_type;
			}

			echo '<script type="application/ld+json">
{
  "@context": "https://schema.org/",
  "@type": "' . esc_attr( $schema_name ) . '",
  "name": "' . esc_attr( $schema_title ) . '",
  "aggregateRating": {
    "@type": "AggregateRating",
    "ratingValue": "' . floatval( $rating_stat['avg'] ) . '",
    "bestRating": "5",
    "ratingCount": "' . absint( $rating_stat['count'] ) . '"
  }
}</script>';
		}

		/**
		 * Add fields after default fields above the comment box, always visible
		 */
		public function comment_form_fields() {

			if ( ! self::status() ) {
				return;
			}

			$require_rating = get_option( 'require_rating', 'no' );
			$stars_style    = get_option( 'stars_style', 'regular' );
			?>
            <div id="stars-rating-review">
                <div class="rating-plate stars-style-<?php echo sanitize_html_class( $stars_style ); ?>">
                    <select id="rate-it" class="require-<?php echo sanitize_html_class( $require_rating ); ?>" name="rating">
						<?php
						$selected_for = 5;
						for ( $i = 1; $i <= 5; $i++ ) {
							$selected = ( $i == $selected_for ) ? "selected" : "";
							echo '<option value="' . esc_attr( $i ) . '" ' . esc_attr( $selected ) . '>' . esc_html( $i ) . '</option>';
						}
						?>
                    </select>
                </div>
            </div>
			<?php
		}

		/**
		 * Add the filter to check whether the comment rating has been set
		 */
		public function verify_comment_rating( $comment_data ) {

			if ( ( isset( $_POST['rating'] ) ) && ( $_POST['rating'] == '' ) ) {

				wp_die( esc_html__( 'Error: You did not add a rating. Hit the Back button on your Web browser and resubmit your comment with a rating.', 'stars-rating' ) );
			}

			return $comment_data;
		}

		/**
		 * Save the comment rating along with comment
		 */
		public function save_comment_rating( $comment_id, $rating = null ) {

			// check if it's a reply then do nothing
			$comment = get_comment( $comment_id, ARRAY_A );
			if ( ! empty( $comment['comment_parent'] ) ) {
				return;
			}

			if ( $rating == null ) {
				if ( ( isset( $_POST['rating'] ) ) && ( $_POST['rating'] != '' ) ) {
					$rating = min( max( 1, $_POST['rating'] ), 5 );
				}
			}

			if ( $rating != null ) {
				add_comment_meta( $comment_id, 'rating', $rating );
			}

		}

		public function get_comment_rating( $comment_id ) {
			$value = get_comment_meta( $comment_id, 'rating', true );

			if ( ! is_numeric( $value ) ) {
				return 0;
			}

			return $value;
		}

		/**
		 * Add the comment rating (saved earlier) to the comment text
		 * You can also output the comment rating values directly to the comments template
		 */
		public function modify_comment( $comment_text ) {

			if ( ! self::status() ) {
				return $comment_text;
			}

			if ( $rating = $this->get_comment_rating( get_comment_ID() ) ) {
				$rating = '<p>' . wp_kses_post( Stars_Rating::get_rating_stars_markup( $rating ) ) . '</p>';

				return $comment_text . $rating;
			} else {
				return $comment_text;
			}
		}

		/**
		 * Display average rating based on approved comments with rating
		 */
		public function rating_stat($postID = null) {

			if ( ! self::status() ) {
				return;
			}

			$args = array(
				'post_id' => ($postID ? $postID : get_the_ID()),
				'status'  => 'approve'
			);

			$comments = get_comments( $args );
			$ratings  = array();
			$count    = 0;

			foreach ( $comments as $comment ) {

				$rating = $this->get_comment_rating( $comment->comment_ID );

				if ( ! empty( $rating ) ) {
					$ratings[] = min( max( 1, $rating ), 5 );
					$count++;
				}
			}

			if ( 0 != count( $ratings ) ) {

				$avg = ( array_sum( $ratings ) / count( $ratings ) );

				return array(
					'avg'   => round( $avg, 1 ),
					'count' => $count
				);
			}

			return false;

		}

		public function rating_average_markup() {

			if ( ! self::status() ) {
				return;
			}

			$rating_stat = $this->rating_stat();

			if ( $rating_stat ) {
				$this->avg_rating_markup( $rating_stat );
			} else if ( comments_open() ) {
				$this->avg_rating_markup( null );
			}
		}

		public function rating_average_shortcode() {

			if ( ! self::status() ) {
				return;
			}

			$rating_stat = $this->rating_stat();

			if ( $rating_stat ) {
				ob_start();
				$this->avg_rating_markup( $rating_stat );
				$output = ob_get_clean();

				return $output;
			}
		}

		/**
		 * Average rating markup that used in shortcode and for default hook over comments form.
		 *
		 * @param $rating_stat
		 *
		 * @return void
		 */
		public function avg_rating_markup( $rating_stat ) {

			echo '<div class="stars-avg-rating">';
			if ( null === $rating_stat ) {
				echo wp_kses_post( Stars_rating::get_rating_stars_markup( 0 ) );
				echo '<span class="rating-text">';
				echo esc_html__( 'Be the first to write a review', 'stars-rating' );
				echo '</span>';
			} else {
				echo wp_kses_post( Stars_Rating::get_rating_stars_markup( $rating_stat['avg'] ) );
				echo '<span class="rating-text">';
				echo floatval( $rating_stat['avg'] ) . ' ' . esc_html__( 'based on', 'stars-rating' ) . ' ' . absint( $rating_stat['count'] ) . ' ' . esc_html__( 'reviews', 'stars-rating' );
				echo '</span>';
			}
			echo '</div>';
		}
	}

endif;

/**
 * Main instance of Stars_Rating.
 *
 * Returns the main instance of Stars_Rating to prevent the need to use globals.
 *
 * @since  1.0.0
 * @return Stars_Rating
 */
function Stars_Rating_Public() {
	return Stars_Rating_Public::instance();
}

// Get Stars_Rating Running.
Stars_Rating_Public();
