# zertifikate erstellen und fetch einrichten

<pre>

#server:
sudo apt install php-openssl
sudo mkdir -p /srv/telepraxis/inbox
sudo chown -R www-data:www-data /srv/telepraxis/inbox
sudo chmod 770 /srv/telepraxis/inbox
  
  
#client:
sudo openssl genpkey \
  -algorithm RSA \
  -pkeyopt rsa_keygen_bits:4096 \
  -out telepraxis_decrypt_private.pem
sudo openssl pkey \
  -in telepraxis_decrypt_private.pem \
  -pubout \
  -out telepraxis_decrypt_public.pem
chmod +x telepraxis_fetch_and_decrypt.sh
./telepraxis_fetch_and_decrypt.sh
 
</pre>
