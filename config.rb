# Require any additional compass plugins here.
require "/Library/Ruby/Gems/2.0.0/gems/susy-1.0.9/lib/susy.rb"

# Set this to the root of your project when deployed:
http_path = "/"
css_dir = "themes/pko/css"
sass_dir = "themes/pko/scss"
images_dir = "themes/pko/images"
javascripts_dir = "themes/pko/javascript"
fonts_dir = "themes/pko/webfonts"

output_style = :nested

relative_assets = true

# To disable debugging comments that display the original location of your selectors. Uncomment:
# line_comments = false
color_output = false


# If you prefer the indented syntax, you might want to regenerate this
# project again passing --syntax sass, or you can uncomment this:
# preferred_syntax = :sass
# and then run:
# sass-convert -R --from scss --to sass themes/pko/scss scss && rm -rf sass && mv scss sass
preferred_syntax = :scss
