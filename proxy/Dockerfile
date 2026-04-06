FROM httpd:2.4-alpine
RUN sed -i \
  -e 's/^#\(LoadModule rewrite_module\)/\1/' \
  -e 's/^#\(LoadModule proxy_module\)/\1/' \
  -e 's/^#\(LoadModule proxy_http_module\)/\1/' \
  -e 's/^#\(LoadModule proxy_wstunnel_module\)/\1/' \
  -e 's/^#\(LoadModule ssl_module\)/\1/' \
  -e 's/^#\(LoadModule socache_shmcb_module\)/\1/' \
  -e 's/^#\(LoadModule headers_module\)/\1/' \
  /usr/local/apache2/conf/httpd.conf
COPY httpd-vhosts.conf /usr/local/apache2/conf/extra/httpd-vhosts.conf
RUN echo 'Include conf/extra/httpd-vhosts.conf' >> /usr/local/apache2/conf/httpd.conf
