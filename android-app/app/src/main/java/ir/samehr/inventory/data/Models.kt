package ir.samehr.inventory.data

data class Product(val id: Long, val name: String, val sku: String?, val stock_quantity: Int?, val stock_status: String, val manage_stock: Boolean, val low_stock: Boolean)
data class ProductsResponse(val items: List<Product>, val page: Int, val pages: Int, val total: Int, val threshold: Int)
data class Summary(val products: Int, val managed: Int, val low_stock: Int, val out_of_stock: Int, val threshold: Int)
data class StockRequest(val mode: String, val amount: Int, val note: String = "Android app")
data class StockResponse(val success: Boolean, val product_id: Long, val stock_before: Int, val stock_after: Int, val stock_status: String)
