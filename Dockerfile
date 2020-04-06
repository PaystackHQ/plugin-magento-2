FROM alexcheng/magento2

WORKDIR /var/www/html/ 

COPY ./setup_magento /usr/local/bin/setup_magento

RUN chmod +x /usr/local/bin/setup_magento

ARG magento_username

ARG magento_password

# setting up authentication credentials for magento
COPY ./auth.json var/composer_home/auth.json

RUN sed -i -e "s/user_placeholder/$magento_username/g" var/composer_home/auth.json && sed -i -e "s/pass_placeholder/$magento_password/g" var/composer_home/auth.json

RUN chown -R www-data:www-data /var/www/html/var/composer_home

# We change to the www-data user who is the magento file system owner. 
# Visit https://devdocs.magento.com/guides/v2.3/install-gde/prereq/file-sys-perms-over.html for more information
USER www-data
     
RUN /var/www/html/bin/magento sampledata:deploy

USER root

ENTRYPOINT [ "setup_magento" ]
