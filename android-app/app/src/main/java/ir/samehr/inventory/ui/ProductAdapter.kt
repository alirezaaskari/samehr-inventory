package ir.samehr.inventory.ui

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.recyclerview.widget.RecyclerView
import ir.samehr.inventory.data.Product
import ir.samehr.inventory.databinding.ItemProductBinding

class ProductAdapter(private val change: (Product, String) -> Unit) : RecyclerView.Adapter<ProductAdapter.Holder>() {
    private val items = mutableListOf<Product>()
    fun submit(newItems: List<Product>) { items.clear(); items.addAll(newItems); notifyDataSetChanged() }
    fun update(id: Long, stock: Int) { val i=items.indexOfFirst{it.id==id}; if(i>=0){ items[i]=items[i].copy(stock_quantity=stock,stock_status=if(stock>0)"instock" else "outofstock"); notifyItemChanged(i) } }
    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int)=Holder(ItemProductBinding.inflate(LayoutInflater.from(parent.context),parent,false))
    override fun getItemCount()=items.size
    override fun onBindViewHolder(holder: Holder, position: Int)=holder.bind(items[position])
    inner class Holder(private val b: ItemProductBinding):RecyclerView.ViewHolder(b.root){ fun bind(p:Product){ b.name.text=p.name; b.meta.text="کد: ${p.sku ?: "—"}  |  وضعیت: ${if(p.stock_status=="instock")"موجود" else "ناموجود"}"; b.stock.text=(p.stock_quantity ?: 0).toString(); b.increase.setOnClickListener{change(p,"increase")}; b.decrease.setOnClickListener{change(p,"decrease")} } }
}
