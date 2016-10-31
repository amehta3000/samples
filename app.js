/**
 * Main app js for tracking clicks on kiosk items and passing data into local storage.
 * Screensaver functionality implemented which will trigger fullscreen video to render if
 * mouse movements are idle.
 * 
 * If this js code is present on page with the list table, then local storage data is printed
 * out via showStorage() method
 */
var SITE = {
  selector: '.cabinets > li > a',
  init: function() {
    var base = this,
        container = $(base.selector),
        i = 0;

    //bind click handlers to all cabinets
    container.each( function() {
        $(this).click(function() { 
          SITE.trackClick($(this).attr('data-id'));
        });
    });    

    $('#clear-btn').click(function() { SITE.clearStorage() });  

    SITE.showStorage();
    SITE.screenSaver.init();
  },
  screenSaver: {
    timerIndex: 0,
    limit: 5,
    selector: "#modal-video",
    init: function() {
      var base = this;
      // console.log("init screensaver");
      //check to see if the mouse moves and reset the timer with debounce      
      $(document).bind('mousemove', function(e) {
           base.resetTimer();
      }.debounce(150));

      //listen to the close modal event
      //reset the timer and reload the page
      $(this.selector).on('hidden.bs.modal', function(e) {
          base.beginTimer();
      });

      base.beginTimer();
    },
    beginTimer: function() {
      //call on init, and after close the modal
      var base = this;
      base.ssID = setInterval(function(){base.check()}, 1000);      
    },
    check: function() {
      //incremental check to see if timer has reached limit
      var base = this;
      // console.log("starting screensaver son: " + base.timerIndex++ );
      if (base.timerIndex>base.limit) { 
        base.stopAndLaunch(); 
      }
    },
    resetTimer: function() {
      var base = this;
      base.timerIndex = 0;
    },    
    stopAndLaunch: function() {
      var base = this;
      // console.log("stop screensaver");
      clearInterval(base.ssID);
      $(this.selector).modal();
    }
  },
  clearStorage: function () {
   if (confirm('Are you sure you want to delete all locale storage?  You will not be able to get this data back.')) {
      localStorage.clear();
      location.reload();
   } else {
      return;
   }      
  },
  //Click handler, updates the count.
  trackClick: function( id ) {
    var cab = "cab_" + id;
    var numClicks = 0;      
    if(localStorage["cab_" + id]) { 
      numClicks = localStorage["cab_" + id]; 
    }
    //increment the value
    numClicks++;
    localStorage["cab_" + id] = numClicks;
    SITE.showStorage();
  },
  showStorage: function() {
    var key = "";
    var list = "<tr><th>Name</th><th>Clicks</th></tr>\n";
    for (var i = 0; i <= localStorage.length - 1; i++) {
        key = localStorage.key(i);
        list += "<tr><td>" + key + "</td>\n<td>" + localStorage.getItem(key) + "</td></tr>\n";
    }
    if (list == "<tr><th>Name</th><th>Clicks</th></tr>\n") {
        list += "<tr><td><i>empty</i></td>\n<td><i>empty</i></td></tr>\n";
    }
    // document.getElementById('list').innerHTML = list;
    $( "#list" ).html( list );
  },     
};