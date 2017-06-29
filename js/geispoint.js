/*
* (c) 2015 Geis CZ s.r.o.
*/
var prevMarkers= [];
    var selectedGeispointId = 0;
    function codeAddress(query,map) 
		{
		geocoder = new google.maps.Geocoder();	
		var address = query;
		geocoder.geocode( { 'address': address}, function(results, status) {
		  if (status == google.maps.GeocoderStatus.OK) {
                        for (var i = 0; i < prevMarkers.length; i++) {
                            prevMarkers[i].setMap(null);
                        }
                        prevMarkers= [];              
                        
			map.setCenter(results[0].geometry.location);
			
			// Add circle overlay and bind to marker
			var circle = new google.maps.Circle({
			  map: map,
			  radius: 5000,    // 5k
			  fillColor: '#AA0000',
			  strokeColor: '#ffcc33',
			  strokeOpacity: 0.8,
			  strokeWeight: 2,
                          center: results[0].geometry.location
			});
			
			map.setZoom(11);
			prevMarkers.push(circle);
	
		  } else {
			//alert("Geocode was not successful for the following reason: " + status);
		  }
		});
	  }
//    function showPointDetail(geisPoint,elementId) {
//        var html = "";
//        html +='<div class="inner">';
//        html +='            <div><div><img src="'+geisPoint.photo_url+'" alt="'+geisPoint.name+'"></div>';
//        html +='                <div>';
//        html +='                    <h3>'+geisPoint.name+'</h3>							';
//        html +='                    <p>';
//        html +='                        '+geisPoint.street+'<br>'+geisPoint.zipcode+'&nbsp;&nbsp;'+geisPoint.city+'';
//        html +='                    </p>';
//        html +='                    <p>';
//        html +='                        tel. '+geisPoint.phone+'<br>';
//        html +='                        email: <a href="mailto:'+geisPoint.email+'" title="Poslat zprávu">'+geisPoint.email+'</a>';
//        html +='                    </p>';
//        html +='                    <p>';
//        html +='                        otevírací doba<br>';
//        html +='                        '+geisPoint.openining_hours+'';
//        html +='                    </p>';
//	html +='						<p>';
//        html +='                        '+geisPoint.id_gp+'';
//        html +='                    </p>';
//        html +='                </div></div>';
//        html +='        </div>';
//        $("#"+elementId+"_detail").html(html);
//    }
    