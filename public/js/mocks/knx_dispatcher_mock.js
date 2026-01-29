// Simple mock adapter for dispatcher prototype
window.knxDispatcherMock = (function(){
  const sample = [
    { id: 'ORD-1001', status: 'new', city: 'madrid', summary: '2 items — Juan P.' , created: Date.now() - 1000*60 },
    { id: 'ORD-1002', status: 'in_progress', city: 'madrid', summary: '1 item — Maria G.', created: Date.now() - 1000*60*5 },
    { id: 'ORD-1003', status: 'in_progress', city: 'barcelona', summary: '3 items — Pedro R.', created: Date.now() - 1000*60*8 },
    { id: 'ORD-1004', status: 'new', city: 'valencia', summary: '1 item — Ana L.', created: Date.now() - 1000*30 },
  ];

  function fetchOrders(opts){
    // opts: { status, city, search }
    return new Promise((resolve)=>{
      setTimeout(()=>{
        let list = sample.slice();
        if (opts && opts.status) list = list.filter(o => o.status === opts.status);
        if (opts && opts.city) list = list.filter(o => o.city === opts.city);
        if (opts && opts.search) list = list.filter(o => (o.id + ' ' + o.summary).toLowerCase().includes(opts.search.toLowerCase()));
        resolve({ success: true, message: 'mock', data: { orders: list, total: list.length } });
      }, 250);
    });
  }

  return { fetchOrders };
})();
