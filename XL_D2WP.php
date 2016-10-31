<?php
	/**
	 * NOTE: Let's do this. 
	 * Custom brute force migration of XLR8R.com current Drupal 5 incarnation into a new Wordpress db.
	 * Will migrate all Nodes -> Categories, posts, attachments (images & audio), authors and users.
	 * Special cases for migrating Podcasts and Downloads category post which have multiple audio attachments.
	 *
	 * Both databases, Drupal and target Wordpress db should be on the same server
	 * Update the site url in the config file. 
	 *
	 * This is a gnarly script, meant to run in browser and is a bit time intensive, 
	 * so be patient, it will get you there.
	*/
         
	ini_set('max_execution_time', 7200); 		// SET EXCUTION TIME BY DEFAULT IT IS 300 SO YOU NEED TO INCREASE FOR LARGE DB
	$file = __DIR__ . "/conn.config.php";     	// file path
	require_once $file;                       	// INCLUDE FILE 
	$databases = $obj->get_database_name();   	// GET DATABSES ON CURRENT DB HOST
	
	$drupal_database = $databases[0];        	// DB NAME FOR DRUPAL DATABASE   
	$wordpress_database = $databases[1];      	// DB NAME FOR WORDPRESS SITE

	$image_arr = array(); 	// IMAGE ARRAY TO FILL UP and RESET 
	$thumb_arr = array();	// THUMB ARRAY TO FILL UP and RESET 

	//REMOTE URL TO PREPEND you want to prepned for mp3s that need absolute paths
	$remote_url = "http://54.172.182.1/wp-content/uploads/";

	/**
	 *	Generatate slug from a string
	 */	
	function pathauto_cleanstring($string){   // FUNCTON TO GENERATE SLUG FROM STRING
		$url = $string;
    	$url = preg_replace('~[^\\pL0-9_]+~u', '-', $url); 		
		// substitutes anything but letters, numbers and '_' with separator
    	$url = trim($url, "-");
    	$url = iconv("utf-8", "us-ascii//TRANSLIT", $url); // TRANSLIT does the whole job, transliterate
    	$url = strtolower($url);
    	$url = preg_replace('~[^-a-z0-9_]+~', '', $url); // keep only letters, numbers, '_' and separator
    	return $url;
	}
	
	/**
	 *	Converts titles into proper strings sutable to be a url
	 */
	function getUrlFriendlyString($str)
	{
	   // convert spaces to '-', remove characters that are not alphanumeric
	   // or a '-', combine multiple dashes (i.e., '---') into one dash '-'.
	   $str = ereg_replace("[-]+", "-", ereg_replace("[^a-z0-9-]", "",
	      strtolower( str_replace(" ", "-", $str) ) ) );
	   return $str;
	}	

	//SELECT ONLY THE DRUPAL NODE TYPES WE ARE MIGRATING
	// get types/categories		
 	$nodetype_query = 'SELECT node_type.name, node_type.type, node_type.description FROM ' . $drupal_database . '.node_type where node_type.name in ("News", "MP3", "Gear", "Video", "Podcast", "Feature", "TV", "Event", "Magazine", "Review")';						
 	//Load in the results
	$nodetype_res = mysql_query($nodetype_query);

	//Loop through node types and insert them as Categories into WP.
	$categoryCount = 0;
	$nodeTypeCount = mysql_num_rows($nodetype_res);
	while($result = mysql_fetch_array($nodetype_res)){
		// ADD POST CATEGORIES NAME AND SLUG        
 		$wp_query = "insert into " . $wordpress_database . ".wp_terms(wp_terms.name,wp_terms.slug) values ('" . $result['name'] . "','" . $result['type'] . "')";       
 		mysql_query($wp_query);
 		//Keep track of last insert id
 		$last_insert_id = $obj->last_insert_id();
 		
 		if ($last_insert_id > 0 ) {
	 		$wp_query_taxnomy = "insert into " . $wordpress_database . ".wp_term_taxonomy(wp_term_taxonomy.term_id,wp_term_taxonomy.taxonomy,wp_term_taxonomy.description) values ('" . $last_insert_id . "','category','" . $result['description'] . "')";	// add Taxonomy 
	 		$status = mysql_query($wp_query_taxnomy);
	 		if ($status == FALSE) {
	 			echo "FAILURE - Insert into taxonomy categories --->" . mysql_error() . "<br>"; 
	 		} else { 
	 			$categoryCount++;
	 		}
 		} else {
 		    $status	= FALSE;
 		}
	}
	
	//This is poor since the staus is over written...
	//Just ignore this shit 
	if($status == FALSE){
		echo "Sorry Data Transfer Failed For Category Migration!! :( <br/> <br/>";
	}
	else {
		echo "Congrates Data Transfer Successfully For Category Migration!!! :) <br/> <br/>";		
	}
	echo "DONE with categories: " . $categoryCount . " of " . $nodeTypeCount . " created.<br/> <br/>";


	//Run the same query as before and now start do some insertion
	$intial_res = mysql_query($nodetype_query);
	echo "Loop through node types again, and start to insert posts in the proper WP Category <br>";




	//BIG LOOP - THROUGH ALL NODE TYPES Loop through the initial $nodetype_res array again... 
	while($intial_query_result = mysql_fetch_array($intial_res)) {

			//GET THE PUBLISHED POSTS == NODES 
		 	$drupal_query = "SELECT node.nid , node.title, node.uid, node.status,  node.created  as create_date ,  node.changed  as modify_date , node_revisions.body as content"; 
			$drupal_query .= " FROM " . $drupal_database . ".node INNER JOIN  " . $drupal_database . ".node_revisions on node.nid = node_revisions.nid  where node.type='" . $intial_query_result['type']  . "' AND node.status = 1";			// SELECT NODE & REVISONS  DATA FROM DRUPAL DATABASE  
			$res = mysql_query($drupal_query);

			//Grab the id of the corresponding WP Category 
			$cat_query = "select wp_terms.term_id from wp_terms where wp_terms.slug='" . $intial_query_result['type'] . "'";          
			$cat_id = mysql_query($cat_query); // GET CATEGORY ID FROM WP
			
			while($cat = mysql_fetch_array($cat_id)) {
				$orig_cat_id = $cat['term_id'];
			}
			
			$cat_query = "select  wp_term_taxonomy.term_taxonomy_id from wp_term_taxonomy where wp_term_taxonomy.term_id=" . $orig_cat_id. "";  //GET TAXONOMY ID
			$term_id_res = mysql_query($cat_query);
			
			while($term = mysql_fetch_array($term_id_res)){
				$orig_term_id = $term['term_taxonomy_id'];
			}

			$wprowcount = 0;
			$nodecount = mysql_num_rows($res);
			echo "<br><br>BEGIN " . $intial_query_result['type'] . " with " . $nodecount . " rows<br>";

			//LOOP THROUGH ALL THE NODES AND CONVERT TO WP POSTS
			while($result = mysql_fetch_array($res)){

				// get slug of the post
				$slug=	pathauto_cleanstring($result['title']); 
				
				// check if slug exists
				$select_slug= "select wp_posts.post_name,wp_posts.id from " . $wordpress_database . ".wp_posts where wp_posts.post_name='" .$slug. "' ";                                            
				$slug_res = mysql_query($select_slug);  									
				
				$slug_arr = mysql_fetch_array($slug_res);
				$slug_cnt = mysql_num_rows($slug_res); 
				
				if ($slug_cnt > 0){                     
					//$slug_cnt = count($slug_arr) + 1 ;
					$post_slug = $slug."-".$slug_cnt;     // if exist than add count to slug.   
					$dup = true;
				}else{
					$post_slug = $slug;
					$dup = false;
				}
				
				// INSERT POST TO WORDPRESS	
				$wp_query = "insert into " . $wordpress_database . ".wp_posts(wp_posts.post_author,wp_posts.post_name,wp_posts.post_title,wp_posts.post_content,wp_posts.post_status,wp_posts.post_date,wp_posts.post_date_gmt,wp_posts.post_modified,wp_posts.post_modified_gmt) values (" . $result['uid'] . ",'" .$post_slug. "','" . addslashes($result['title']) . "','" . addslashes($result['content']) . "','publish',FROM_UNIXTIME(" . $result['create_date'] . "),FROM_UNIXTIME(" . $result['create_date'] . "),FROM_UNIXTIME(" . $result['modify_date'] . "),FROM_UNIXTIME(" . $result['modify_date'] . ")) ON DUPLICATE KEY UPDATE wp_posts.post_name= wp_posts.post_name +1; ";
				mysql_query($wp_query);              
								
				$wprowcount += 1;					
				//Current wp post id			
 				$cur_wp_insert_id = $obj->last_insert_id();
 				

				//Podcast are not stored in the files so we need to pull it from the node_podcast table and fill in the info.
 				if ($intial_query_result['type']  == 'podcast') {
 					//Pull the id of this nid and get the podcast file urls for the mp3 and m4a
 					$select_node_podcast = "SELECT  node_podcast.field_mp3_path_value as mp3, node_podcast.field_m4a_path_value as m4a FROM ".$drupal_database.".node_podcast WHERE nid=".$result['nid']. "";
 					$node_podcast_res = mysql_query($select_node_podcast); 
 					$node_podcast_arr = mysql_fetch_array($node_podcast_res);
 					$podcast_m4a_file_path = $remote_url . $node_podcast_arr['m4a'];
 					$podcast_mp3_file_path = $remote_url . $node_podcast_arr['mp3'];
 					// echo "PC -- " .$podcast_m4a_file_path. " | " . podcast_mp3_file_path . "<br>";

 					$pod_title_mp3 = pathinfo($node_podcast_arr['mp3']);
 					$pod_name_mp3 = $pod_title_mp3['basename'];
 					$pod_title_m4a = pathinfo($node_podcast_arr['m4a']);
 					$pod_name_m4a = $pod_title_m4a['basename'];

 					$pod_name = substr($pod_name_mp3,0,-4)."<br>";
 					//$pod_name = strtr($pod_name, "_", " ");
 					//echo $pod_name . "<br>";	

 					//Write out urls to the bottom the post content.
					$select_podcast= "select  wp_posts.post_content from " . $wordpress_database . ".wp_posts where wp_posts.ID='" . $cur_wp_insert_id. "' "; 				//get content from parent post
					$select_podcast_res= mysql_query($select_podcast);
					$podcast_post_data = mysql_fetch_array($select_podcast_res);

					$podcast_str = "</br></br><a href='".$podcast_mp3_file_path."' download='".$pod_name_mp3."' class='link_only'>Download MP3</a>";
				    $podcast_str .= "</br><a href='".$podcast_m4a_file_path."' download='".$pod_name_m4a."' class='link_only'>Download M4A (iTunes enhanced)</a>";
				    $podcast_str .= "</br><a href='http://www.xlr8r.com/music/podcasts/feeds/rss'>Subscribe to Podcast (RSS)</a>";
					$podcast_str .= "<br></br><a href='".$podcast_mp3_file_path."'>" . $pod_name . "</a><br>";
				      
					// echo "mp3_str =  " . $mp3_str . " | " . $cur_wp_insert_id . "<br>";
					//add mp3 file link at the end of content									
			   		$podcast_post_data['post_content'] .= $podcast_str;											
					$podcastpostdata = mysql_real_escape_string($podcast_post_data['post_content']);

					//Update the podcast title
					$select_pod_number = "select * from " . $drupal_database . ".node_data_field_issue_number where nid = " . $result['nid']. "";
					$select_pod_number_res = mysql_query($select_pod_number); 
					if (mysql_num_rows($select_pod_number_res) > 0){
						$select_pod_number_arr = mysql_fetch_array($select_pod_number_res);
						$podcast_number = $select_pod_number_arr['field_issue_number_value'];
						$new_podcast_tile = "Podcast " . $podcast_number . ": " . $result['title'];
						echo $new_podcast_tile . "<br>";

						$update_podcast_title = "UPDATE " . $wordpress_database . ".`wp_posts` SET `post_title` = ' " . addslashes($new_podcast_tile) . " '  WHERE `wp_posts`.`ID` =" . $cur_wp_insert_id . " "; 		
						$update_podcast_status=  mysql_query($update_podcast_title);			
					}


					//

					$update_podcast_content = "UPDATE " . $wordpress_database . ".`wp_posts` SET `post_content` = ' " .$podcastpostdata . " '  WHERE `wp_posts`.`ID` =" . $cur_wp_insert_id . " "; 		
					$update_podcast_status =  mysql_query($update_podcast_content);
 				}


 				//Get the Artist name and the Label
 				if ($intial_query_result['type']  == 'download' || $intial_query_result['type']  == 'review') {
 					//Find the artist Name
 					$select_artist_name = "select * from " . $drupal_database . ".node_data_field_artist where nid = " . $result['nid']. "";
					$select_artist_res = mysql_query($select_artist_name); 
					if (mysql_num_rows($select_artist_res) > 0){
						$select_artist_arr = mysql_fetch_array($select_artist_res);
						//Keep artist_name for tags.
						$artist_name = $select_artist_arr['field_artist_value'];
						echo "<br>" . $select_artist_arr['field_artist_value'] . " -> ";

	 					//Update the title of the post with Artist name "post name"
						$select_post_title= "select  wp_posts.post_title from " . $wordpress_database . ".wp_posts where wp_posts.ID='" . $cur_wp_insert_id. "' "; 				//get content from parent post
						$select_post_title_res= mysql_query($select_post_title);
						$select_post_title_arr = mysql_fetch_array($select_post_title_res);


	 					//Find the Label
	 					$select_label_name = "select * from " . $drupal_database . ".node_data_field_label where nid = " . $result['nid']. "";
						$select_label_res = mysql_query($select_label_name); 
						if (mysql_num_rows($select_label_res) > 0){
							$select_label_arr = mysql_fetch_array($select_label_res);
							$label_name_url = $select_label_arr['field_label_value'];
							$label_name = "*" . strip_tags($label_name_url) . "*";
							echo strip_tags($select_label_arr['field_label_value']) . " => ";
						}else{
							$label_name = "";
						}						
						
						//HACK we are giong to add the LABEL to the title and then update in wordpress to make the label a tag 
						//and update the post.
						$update_artist_name = $artist_name . " \"" . $select_post_title_arr['post_title'] . "\"" . $label_name;
						
						//Double hack, tack on the Rating
						if ($intial_query_result['type']  == 'review') {
		 					$select_rating = "select * from " . $drupal_database . ".node_review where nid = " . $result['nid']. "";
							$select_rating_res = mysql_query($select_rating); 
							//field_xlr8r_rating_value

							if (mysql_num_rows($select_rating_res) > 0){
								$select_rating_arr = mysql_fetch_array($select_rating_res);
								$rating = $select_rating_arr['field_xlr8r_rating_value'];								
								echo $rating . " => ";
							}else{
								$rating = "";
							}	

							if ($rating !== NULL && $rating !== "") {
								$update_artist_name .= "|".$rating."|";
							}

						}


						echo $update_artist_name . "<br>";
						$newposttitle = mysql_real_escape_string($update_artist_name);
						
						$update_post_title = "UPDATE " . $wordpress_database . ".`wp_posts` SET `post_title` = ' " .$newposttitle . " '  WHERE `wp_posts`.`ID` =" . $cur_wp_insert_id . " "; 		
						$update_post_status=  mysql_query($update_post_title);			

					}
				

 				}

				// Select files data from files table DDB that belong to this node
 				$atatchment_query = "select files.filename as attach,files.filepath as fileurl, files.filemime as mimetype from " . $drupal_database . ".files where files.nid=" . $result['nid']. "";
 				$attach = mysql_query($atatchment_query);  

				// $upload_dir = "http://xlr8r-files-092014.s3.amazonaws.com/wp-content/uploads/ARCHIVE/";
 				//LOOP THROUGH ATTACHMENT DATA PERTAINING TO NODE AND CONVERT TO WP ATTACHMENT POSTS IMAGE/AUDIO
 				while($attach_data = mysql_fetch_array($attach)){
				 	//define upload direcotry for media files and attachments. 		
				 	// $upload_dir = WEBSITE . "/wp-content/uploads/2014/08/"; 	
					
					//$upload_dir = WEBSITE . "/wp-content/uploads/ARCHIVE/"; 	

 					// $remote_url = "http://54.172.182.1/wp-content/uploads/";

					$og_file_path = $remote_url . $attach_data['fileurl'];

					$file_title = pathinfo($attach_data['attach']);   // FILE INFO 
					$file_path= pathinfo($attach_data['fileurl']);	  // FILE URL 
		 			$file_path_url= $file_path['basename'];           //file
					$filename= $file_path['filename'];                //filename  
					$dirname = $file_path['dirname'];
									
					if (strpos($attach_data['fileurl'],'.mp3') !== false) 
						$is_actually_mp3 = "true";
					else 
						$is_actually_mp3 = "false";					

					echo "> IS MP3 > " . $is_actually_mp3;
					unset($post_title);
					unset($fileslug);
		
					foreach($file_title as $post) {
		 				$post_title[] = $post;
		 			}
				
					$fileslug = pathauto_cleanstring($post_title[3]);    // get slug for attachents 	
					echo "-->".$fileslug;
			 		$wp_attachment_query = "insert into " . $wordpress_database . ".wp_posts(wp_posts.post_author,wp_posts.post_name,wp_posts.post_title,wp_posts.post_status,wp_posts.post_date,wp_posts.post_date_gmt,wp_posts.post_modified,wp_posts.post_modified_gmt,wp_posts.post_type,wp_posts.guid,wp_posts.post_parent,wp_posts.post_mime_type)";
					$wp_attachment_query .="values (" . $result['uid'] . ",\"" . $fileslug . "\", \"" . $post_title[3] . "\",'inherit',FROM_UNIXTIME(" . $result['create_date'] . "),FROM_UNIXTIME(" . $result['create_date'] . "),FROM_UNIXTIME(" . $result['modify_date'] . "),FROM_UNIXTIME(" . $result['modify_date'] . "),'attachment',\"" .  $og_file_path. "\"," . $cur_wp_insert_id . ",'" . $attach_data['mimetype'] . "')";
								
					$att_data= mysql_query($wp_attachment_query); //insert attachements to wordpress
					if ($fileslug == "midlake-roscoe-beyond-the-wizard-s-sleeve-remix") {

						echo "<br><br>". $wp_attachment_query. "<br><br>";
					}
					
					if($att_data){
						$last_attachment_id = $obj->last_insert_id();
						echo "--->found att_data->" . $last_attachment_id . "||";
						// $up_dir= "2014/08/".$attach_data['attach'];
						//////// $up_dir= $upload_dir.$attach_data['attach'];
						
						//IMAGES
						if (($attach_data['mimetype'] == "image/jpeg" || $attach_data['mimetype'] == "image/png" || $attach_data['mimetype'] == "image/jpg") && $is_actually_mp3 == "false") {     // save meta info for images 
							echo " IMAGE <br>";
							$wp_thumb_data ="INSERT INTO " . $wordpress_database . ".wp_postmeta (wp_postmeta.post_id,wp_postmeta.meta_key,wp_postmeta.meta_value) values (".$last_attachment_id.",'_wp_attached_file','".$og_file_path."' )";
							mysql_query($wp_thumb_data);

							$image_arr[count($image_arr)] = "'" . $result['nid'] . "," . $last_attachment_id . "," . $attach_data['fileurl'] . ", " . $dirname . ", " . $attach_data['attach'] . "'<br>"; 

							//echo $wp_thumb_data."</br>";
							$att_meta_data = serialize(array( "height"=>'375',"width"=>"750"));
							
							$wp_thumb_attachment_meta =" insert into " . $wordpress_database . ".wp_postmeta (wp_postmeta.post_id,wp_postmeta.meta_key,wp_postmeta.meta_value) values (".$last_attachment_id.",'_wp_attachment_metadata','".$att_meta_data."' )";
							
							mysql_query($wp_thumb_attachment_meta);

							//This is wack it makes every image have a thumbnail 
							//Check to see if the image is in a dir not just as file, then we know it was a thumbnail of some sort.
							if ($dirname != "files") {
							 	$wp_thumb_meta =" insert into " . $wordpress_database . ".wp_postmeta (wp_postmeta.post_id,wp_postmeta.meta_key,wp_postmeta.meta_value) values (".$cur_wp_insert_id.",'_thumbnail_id',".$last_attachment_id." )";
							 	//Testing debug
	 							$thumb_arr[count($thumb_arr)] = "'" . $result['nid'] . "," . $last_attachment_id . "," . $attach_data['fileurl'] . ", " . $dirname . ", " . $attach_data['attach'] . "'<br>"; 
								mysql_query($wp_thumb_meta);
						 	}

						} //AUDIO  
						else if (($attach_data['mimetype'] == "audio/mpeg" || $attach_data['mimetype'] == "audio/mp3" || $is_actually_mp3 == "true") && $intial_query_result['type'] != 'podcast') {
							echo " AUDIO <br>";
							// //////////$mp3_url= $upload_dir.$file_path_url;   	// add meta for audio files 	
							$select_content= "select  wp_posts.post_content from " . $wordpress_database . ".wp_posts where wp_posts.ID='" . $cur_wp_insert_id. "' "; 				//get content from parent post
							$updated_content= mysql_query($select_content);
							$post_data = mysql_fetch_array($updated_content);
							/////////////////////							
								// $mp3_str = "</br></br><a href='".$mp3_url."'>".$post_title[3]."</a>";  
							/////////////////////	
							$mp3_str = "<br><br><a href='".$og_file_path."'>".$post_title[3]."</a>";  
							echo "  " . $og_file_path . " | " . $cur_wp_insert_id . "<br>";
							//add mp3 file link at the end of content									
					   		$post_data['post_content'] .= $mp3_str;
													
							$postdata = mysql_real_escape_string($post_data['post_content']);

							$update_content = "UPDATE " . $wordpress_database . ".`wp_posts` SET `post_content` = ' " .$postdata . " '  WHERE `wp_posts`.`ID` =" . $cur_wp_insert_id . " "; 		
								$update_status=  mysql_query($update_content);
								
						}
						unset($update_content);
						unset($updated_content);
						unset($mp3_str);
						unset($file_path_url);
					} //END ATT_DATA
				} //END ATTACHMENT LOOP 
						
 				//Add thse posts under the proper category
 				$wp_taxnomy_query = "insert into wp_term_relationships(wp_term_relationships.object_id,wp_term_relationships.term_taxonomy_id) values (" . $cur_wp_insert_id . "," . $orig_term_id  . ")";           				// terms & relationships. 
 				$status = mysql_query($wp_taxnomy_query);	
 				if($status == FALSE){
 					echo "-->FAILED TAXONOMY WP ID: " .  $cur_wp_insert_id . "<br>" ;
 				}
 			} //END NODE LOOP
 			echo "<br>COMPLETED " . $intial_query_result['type'] . " with " . $wprowcount . " out of " . $nodecount . " POSTS migrated!!<br><br>";	
 			
		} //END NODE TYPS LOOP

	//UPDATE  POSTS FILES LINKS WITH NEW DIRECTORY LINKS 	
//////////////////		
	// $wpupdateimagelinks ="UPDATE ". $wordpress_database .".`wp_posts` SET `post_content` = REPLACE(`post_content`, 'http://www.xlr8r.com/files/', 'http://xlr8r-files-092014.s3.amazonaws.com/wp-content/uploads/ARCHIVE/')";
	// mysql_query($wpupdateimagelinks);
	// echo "UPDATED ALL THE URLS to S3 Son! <br>";
//////////////////	

	// if($status == FALSE){
	// 	echo "Sorry Data Transfer Failed For Posts Migration!! :( <br/> <br/>";
	// }
	// else {
	// 	echo "Congrates Data Transfer Successfully For Posts Migration!!! :) <br/> <br/>";		
	// }

# AUTHORS
// $author_users_query = "INSERT IGNORE INTO " . $wordpress_database . ".wp_users (ID, user_login, user_pass, user_nicename, user_email, user_registered, user_activation_key, user_status, display_name) SELECT DISTINCT u.uid, u.mail, NULL, u.name, u.mail, FROM_UNIXTIME(created), '', 0, u.name
//     FROM" . $drupal_database . ".users u INNER JOIN " . $drupal_database . ".users_roles r USING (uid)
//     WHERE (1 )";
//////////////////////////////////////

$author_users_query = "INSERT IGNORE INTO " . $wordpress_database . ".wp_users (ID, user_login,  user_nicename, user_email, user_registered, user_activation_key, user_status, display_name) SELECT DISTINCT u.uid, u.name, u.name, u.mail, FROM_UNIXTIME(created), '', 0, u.name
    FROM " . $drupal_database . ".users u";
mysql_query($author_users_query);  
echo "--INSERTED AUTHORS <br>".$author_users_query. "<br>";  


//Author role in user_meta
$author_c = 'a:1:{s:6:"author";s:1:"1";}';
// $author_role_query = "INSERT IGNORE INTO ". $wordpress_database . ".wp_usermeta (user_id, meta_key, meta_value) SELECT DISTINCT u.uid, 'wp_capabilities', '" . $author_c . "' FROM " . $drupal_database . ".users u INNER JOIN drupal_test.users_roles r USING (uid) WHERE (1)";
$author_role_query = "INSERT IGNORE INTO ". $wordpress_database . ".wp_usermeta (user_id, meta_key, meta_value) SELECT DISTINCT ID, 'wp_capabilities', '" . $author_c . "' FROM " . $wordpress_database . ".wp_users where ID > 1" ;
mysql_query($author_role_query);
echo "--INSERTED Author roles <br>" . $author_role_query . "<br>";  

$author_role_query2 = "INSERT IGNORE INTO " . $wordpress_database . ".wp_usermeta (user_id, meta_key, meta_value) SELECT DISTINCT u.uid, 'wp_user_level', '2' FROM " . $drupal_database . ".users u " ;
mysql_query($author_role_query2);
echo "--INSERTED Author roles PTII <br>" . $author_role_query2 . "<br>";  

//Make me an admin
$admin_c = 'a:1:{s:13:"administrator";s:1:"1";}';
$author_update1 = "UPDATE " . $wordpress_database . ".wp_usermeta SET meta_value = '" . $admin_c . "' WHERE user_id IN (1) AND meta_key = 'wp_capabilities'";
mysql_query($author_update1);
echo "--UPDATED Author capabilities<br>" . $author_update1 . "<br>";  

$author_update2 = "UPDATE " . $wordpress_database . ".wp_usermeta SET meta_value = '10' WHERE user_id IN (1) AND meta_key = 'wp_user_level'";
mysql_query($author_update2);
echo "--UPDATED Author capabilities PT II<br>" . $author_update2 . "<br>";  

$wp_users = "SELECT ID, user_nicename, display_name from " . $wordpress_database . ".wp_users";
$nicename = mysql_query($wp_users);  
echo "NICENAME: " . mysql_num_rows($nicename) . "<br>";
//Do this loop after and set the nickname on all these fools.
while($nicename_data = mysql_fetch_array($nicename)){
	$big_name = $nicename_data['user_nicename'];
	$dis_name = $nicename_data['display_name'];
	$clean_name = getUrlFriendlyString($big_name);
	$update_nicename = "UPDATE " . $wordpress_database . ".wp_users SET user_login = '" . $clean_name . "', user_nicename = '" . $clean_name . "' WHERE ID = " . $nicename_data["ID"];
	mysql_query($update_nicename);  	

	$insert_nickname = "INSERT IGNORE INTO ". $wordpress_database . ".wp_usermeta (user_id, meta_key, meta_value) VALUES " . $nicename_data["ID"] . ", 'nickname', '" . $dis_name;
	mysql_query($insert_nickname);  	
}

$post_author_query = "UPDATE " . $wordpress_database . ".wp_posts SET post_author = NULL WHERE post_author NOT IN (SELECT DISTINCT ID FROM " . $wordpress_database .".wp_users)";
mysql_query($post_author_query);
echo "--UPDATED wp_posts so authoer will be null if no author <br>" . $post_author_query . "<br>";  

//////////////////////////////////////

/*$tagsall ="INSERT INTO " . $wordpress_database . ".wp_term_taxonomy(term_id ,taxonomy)
SELECT   term_id,'post_tag'FROM " . $wordpress_database . ".wp_terms";
mysql_query($tagsall);

$tags3 =" INSERT INTO " . $wordpress_database . ".wp_term_relationships (object_id, term_taxonomy_id) 
SELECT  wpp.ID, wptt.term_taxonomy_id
FROM  " . $drupal_database . ".vocabulary tv,
 " . $drupal_database . ".term_data td,
 " . $wordpress_database . ".wp_terms wpt,
 " . $wordpress_database . ".wp_term_taxonomy wptt,
 " . $drupal_database . "node dn,
 " . $wordpress_database . ".wp_posts wpp
WHERE td.tid = tv.vid AND
 wpt.name = td.name and 
 wptt.term_id = wpt.term_id AND
 dn.nid = tv.entity_id AND
 wpp.post_title = dn.title ";

mysql_query($tags3);


$updatetags=" UPDATE " . $wordpress_database . ".wp_term_taxonomy wptt
SET count = (   SELECT count(*)   FROM " . $wordpress_database . ".wp_term_relationships wptr
 WHERE wptr.term_taxonomy_id = wptt.term_taxonomy_id  GROUP by term_taxonomy_id
)";
mysql_query($updatetags);

*/

// Update posts category  counts.
$wpupdate= "UPDATE wp_term_taxonomy SET count = ( SELECT COUNT(*) FROM wp_term_relationships rel 
    LEFT JOIN wp_posts po ON (po.ID = rel.object_id)    WHERE rel.term_taxonomy_id = wp_term_taxonomy.term_taxonomy_id 
        AND wp_term_taxonomy.taxonomy NOT IN ('link_category') AND po.post_status IN ('publish', 'future')
)";

mysql_query($wpupdate);   // UPDATE TERMS AND POSTS COUNT

echo "DONE WITH THIS, ALL YOUR DRUPAL ARE BELONG TO US";
// echo "<br>IMAGES: " . count($image_arr) . "<br>";
// print_r($image_arr);

// echo "<BR><BR><br>THUMBNAILS: " . count($thumb_arr) . "<br>";
// print_r($thumb_arr);



?>
